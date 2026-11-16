<?php

namespace App\Services\Payments;

use App\Enums\AdminNotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Events\DomainEvent;
use App\Models\AdminNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Services\AuditService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockEffects;
use App\Services\Orders\OrderState;
use App\Services\Orders\OrdersService;
use App\Services\Orders\OrderWorkflowService;
use App\Services\Orders\PaymentLedgerService;
use App\Services\Payments\Providers\PaymentProviderFactory;
use App\Services\SettingsService;
use App\Support\AdminPrincipal;
use App\Support\Format;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentsService
{
    public function __construct(
        private readonly PaymentProviderFactory $factory,
        private readonly PaymentLedgerService $ledger,
        private readonly OrderWorkflowService $workflow,
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    private function toDto(Payment $p): array
    {
        $order = $p->order ?? Order::findOrFail($p->order_id);

        return [
            'id' => $p->id,
            'status' => $p->status,
            'amount' => $p->amount,
            'orderNumber' => $order->number,
            'orderTrackingToken' => $order->tracking_token,
            'instructions' => $p->instructions,
            'isMock' => $p->provider === 'mock',
            'failureReason' => $p->failure_reason,
        ];
    }

    /** Étape 1 du flux mobile money : création du paiement PENDING et appel de l'opérateur. */
    public function initiate(array $dto): array
    {
        $order = Order::where('number', $dto['orderNumber'])->where('tracking_token', $dto['trackingToken'])->first()
            ?? throw new HttpException(404, 'Commande introuvable');
        if (in_array($order->status, [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value, OrderStatus::DELIVERED->value], true)) {
            throw new HttpException(400, 'Cette commande ne peut plus être payée');
        }
        $settings = $this->settings->get('payments');
        if (($dto['method'] === PaymentMethod::AIRTEL_MONEY->value && ! $settings['airtelMoney']) || ($dto['method'] === PaymentMethod::MOOV_MONEY->value && ! $settings['moovMoney'])) {
            throw new HttpException(400, "Ce moyen de paiement n'est pas disponible");
        }
        $payerPhone = Format::normalizeGabonPhone($dto['payerPhone']) ?? throw new HttpException(400, 'Numéro mobile money invalide');

        $initialDue = OrdersService::initialDue($order, OrderItem::where('order_id', $order->id)->get());
        if ($order->amount_paid < $initialDue) {
            $amount = $initialDue - $order->amount_paid;
            $kind = $initialDue >= $order->total ? PaymentKind::FULL : PaymentKind::DEPOSIT;
        } else {
            $amount = $order->balance_due;
            $kind = PaymentKind::BALANCE;
        }
        if ($amount <= 0) {
            throw new HttpException(400, "Il n'y a rien à payer pour cette commande");
        }

        $provider = $this->factory->forMethod($dto['method']);
        $payment = DB::transaction(function () use ($order, $provider, $dto, $amount, $kind, $payerPhone, $settings) {
            Payment::where('order_id', $order->id)->where('status', PaymentRecordStatus::PENDING->value)
                ->update(['status' => PaymentRecordStatus::FAILED->value, 'failure_reason' => 'Remplacé par une nouvelle tentative']);

            return Payment::create([
                'order_id' => $order->id,
                'provider' => $provider->code(),
                'method' => $dto['method'],
                'amount' => $amount,
                'currency' => 'XAF',
                'kind' => $kind->value,
                'status' => PaymentRecordStatus::PENDING->value,
                'payer_phone' => $payerPhone,
                'provider_reference' => substr(strtoupper('P'.base_convert((string) (int) (microtime(true) * 1000), 10, 36).preg_replace('/[^a-zA-Z0-9]/', '', Format::randomToken(6))), 0, 30),
                'expires_at' => now()->addMinutes((int) $settings['expiryMinutes']),
            ])->refresh();
        });

        try {
            $result = $provider->initiate($payment);
        } catch (\Throwable $e) {
            Log::error("Initiation {$provider->code()} impossible : {$e->getMessage()}");
            $result = ['providerReference' => $payment->provider_reference, 'status' => PaymentRecordStatus::FAILED->value, 'instructions' => "L'opérateur est momentanément indisponible. Réessayez dans quelques instants."];
        }
        $payment->instructions = $result['instructions'] ?? null;
        $payment->provider_transaction_id = $result['providerTransactionId'] ?? null;
        if ($result['status'] === PaymentRecordStatus::FAILED->value) {
            $payment->status = PaymentRecordStatus::FAILED->value;
            $payment->failure_reason = $result['instructions'] ?? "Paiement refusé par l'opérateur";
        }
        $payment->save();
        $orderPatch = ['payment_method' => $dto['method']];
        if ($order->amount_paid === 0) {
            $orderPatch['payment_status'] = $payment->status === PaymentRecordStatus::FAILED->value ? PaymentStatus::FAILED->value : PaymentStatus::PENDING->value;
        }
        $order->update($orderPatch);
        $payment->setRelation('order', $order);

        return $this->toDto($payment);
    }

    private function findForToken(int $id, string $token): Payment
    {
        $p = Payment::with('order')->find($id);
        if (! $p || ! hash_equals($p->order->tracking_token, $token)) {
            throw new HttpException(404, 'Paiement introuvable');
        }

        return $p;
    }

    public function status(int $id, string $token): array
    {
        return $this->toDto($this->findForToken($id, $token));
    }

    /** « J'ai validé » : interroge l'opérateur (serveur à serveur). */
    public function check(int $id, string $token): array
    {
        $p = $this->findForToken($id, $token);
        if ($p->status === PaymentRecordStatus::PENDING->value) {
            $this->pollOne($p);
        }

        return $this->toDto(Payment::with('order')->findOrFail($id));
    }

    /** Simulation (mode test uniquement) : génère un webhook signé et le traite comme un vrai. */
    public function simulate(int $id, string $token, string $outcome): array
    {
        if (! $this->factory->isMock()) {
            throw new HttpException(400, 'Simulation disponible uniquement en mode test');
        }
        $p = $this->findForToken($id, $token);
        ['headers' => $headers, 'rawBody' => $rawBody] = $this->factory->mock->buildWebhook($p, $outcome);
        $this->handleWebhook('mock', $headers, $rawBody);

        return $this->status($id, $token);
    }

    /**
     * Webhook opérateur : seule source de vérité (signature, montant, devise, idempotence, transaction).
     *
     * @param  array<string, string>  $headers  en-têtes en minuscules
     */
    public function handleWebhook(string $providerCode, array $headers, string $rawBody): array
    {
        if ($rawBody === '') {
            throw new HttpException(400, 'Corps de requête vide');
        }
        $provider = $this->factory->byCode($providerCode);
        $signatureValid = $provider->verifyWebhook($headers, $rawBody);
        try {
            $raw = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            $event = $provider->parseWebhook($rawBody);
        } catch (\Throwable) {
            throw new HttpException(400, 'Notification illisible');
        }

        try {
            $stored = PaymentEvent::create([
                'provider' => $providerCode,
                'event_id' => mb_substr($event['eventId'], 0, 190),
                'raw_payload' => $raw,
                'signature_valid' => $signatureValid,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return ['received' => true, 'duplicate' => true];
            }
            throw $e;
        }
        if (! $signatureValid) {
            $stored->update(['outcome' => 'invalid_signature', 'processed_at' => now()]);
            throw new HttpException(401, 'Signature invalide');
        }
        $payment = Payment::where('provider_reference', $event['providerReference'])->first();
        if (! $payment) {
            $stored->update(['outcome' => 'unknown_reference', 'processed_at' => now()]);

            return ['received' => true];
        }
        $stored->update(['payment_id' => $payment->id]);

        $emits = [];
        $effects = new StockEffects;
        $outcome = DB::transaction(function () use ($payment, $event, &$emits, $effects) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $pending = PaymentRecordStatus::PENDING->value;
            if ($locked->status !== $pending && ! ($locked->status === PaymentRecordStatus::FAILED->value && $event['status'] === PaymentRecordStatus::SUCCEEDED->value)) {
                return 'already_processed';
            }
            if ($event['status'] === PaymentRecordStatus::SUCCEEDED->value) {
                if ((isset($event['amount']) && (int) $event['amount'] !== $locked->amount) || (! empty($event['currency']) && $event['currency'] !== $locked->currency)) {
                    AdminNotification::create([
                        'type' => AdminNotificationType::DUPLICATE_PAYMENT->value,
                        'title' => "Écart de montant sur le paiement {$locked->provider_reference} (".($event['amount'] ?? '').' '.($event['currency'] ?? '').')',
                        'link' => "/paiements?orderId={$locked->order_id}",
                    ]);

                    return 'amount_mismatch';
                }

                return $this->applySuccess($locked, $event['providerTransactionId'] ?? null, $emits);
            }
            if (in_array($event['status'], [PaymentRecordStatus::FAILED->value, PaymentRecordStatus::EXPIRED->value], true)) {
                return $this->applyFailure($locked, $event['status'], $event['failureReason'] ?? "Paiement refusé par l'opérateur", $emits);
            }

            return 'pending';
        });
        $stored->update(['outcome' => $outcome, 'processed_at' => now()]);
        $this->inventory->flush($effects);
        foreach ($emits as [$name, $payload]) {
            DomainEvent::emit($name, $payload);
        }

        return ['received' => true, 'outcome' => $outcome];
    }

    private function applySuccess(Payment $payment, ?string $txId, array &$emits): string
    {
        $payment->forceFill([
            'status' => PaymentRecordStatus::SUCCEEDED->value,
            'paid_at' => now(),
            'provider_transaction_id' => $txId ?? $payment->provider_transaction_id,
            'failure_reason' => null,
        ])->save();
        $res = $this->ledger->applySucceeded($payment);
        $emits[] = [$res['duplicate'] ? DomainEvent::PAYMENT_DUPLICATE : DomainEvent::PAYMENT_SUCCEEDED, ['paymentId' => $payment->id, 'orderId' => $payment->order_id]];

        return $res['duplicate'] ? 'duplicate_payment' : 'succeeded';
    }

    private function applyFailure(Payment $payment, string $status, string $reason, array &$emits): string
    {
        Payment::whereKey($payment->id)->update(['status' => $status, 'failure_reason' => mb_substr($reason, 0, 500)]);
        $order = $this->ledger->lockOrder($payment->order_id);
        if ($order->amount_paid === 0 && $order->status === OrderStatus::PENDING_PAYMENT->value) {
            Order::whereKey($order->id)->update(['payment_status' => PaymentStatus::FAILED->value]);
        }
        $emits[] = [DomainEvent::PAYMENT_FAILED, ['paymentId' => $payment->id, 'orderId' => $payment->order_id]];

        return 'failed';
    }

    private function pollOne(Payment $p): void
    {
        try {
            $res = $this->factory->byCode($p->provider)->checkStatus($p->provider_reference);
            Payment::whereKey($p->id)->update(['last_checked_at' => now()]);
            if ($res['status'] === PaymentRecordStatus::PENDING->value) {
                return;
            }
            $emits = [];
            DB::transaction(function () use ($p, $res, &$emits) {
                $locked = Payment::whereKey($p->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PaymentRecordStatus::PENDING->value) {
                    return;
                }
                $res['status'] === PaymentRecordStatus::SUCCEEDED->value
                    ? $this->applySuccess($locked, $res['providerTransactionId'] ?? null, $emits)
                    : $this->applyFailure($locked, $res['status'], $res['failureReason'] ?? "Paiement refusé par l'opérateur", $emits);
            });
            foreach ($emits as [$name, $payload]) {
                DomainEvent::emit($name, $payload);
            }
        } catch (\Throwable $e) {
            Log::warning("Vérification du paiement {$p->provider_reference} impossible : {$e->getMessage()}");
        }
    }

    /** Toutes les 5 min : vérification des paiements en attente, expiration et libération du stock. */
    public function reconcilePending(): void
    {
        try {
            $now = now();
            $pending = Payment::where('status', PaymentRecordStatus::PENDING->value)->where('created_at', '<', $now->copy()->subMinutes(2))->limit(200)->get();
            foreach ($pending as $p) {
                if ($p->expires_at && $p->expires_at->lt($now)) {
                    $emits = [];
                    DB::transaction(function () use ($p, &$emits) {
                        $locked = Payment::whereKey($p->id)->lockForUpdate()->firstOrFail();
                        if ($locked->status === PaymentRecordStatus::PENDING->value) {
                            $this->applyFailure($locked, PaymentRecordStatus::EXPIRED->value, 'Délai de validation dépassé', $emits);
                        }
                    });
                    foreach ($emits as [$name, $payload]) {
                        DomainEvent::emit($name, $payload);
                    }
                } else {
                    $this->pollOne($p);
                }
            }
            $this->expireUnpaidOrders();
        } catch (\Throwable $e) {
            Log::error("Rapprochement des paiements en attente impossible : {$e->getMessage()}");
        }
    }

    /** Commandes non payées après le délai : annulation et libération des réservations (règle 6.2). */
    public function expireUnpaidOrders(): int
    {
        $limit = now()->subMinutes((int) $this->settings->get('payments')['expiryMinutes']);
        $count = 0;
        foreach (Order::where('status', OrderStatus::PENDING_PAYMENT->value)->where('created_at', '<', $limit)->limit(200)->get() as $o) {
            if ($o->payment_method === PaymentMethod::CASH_ON_DELIVERY->value) {
                continue;
            }
            if (Payment::where('order_id', $o->id)->where('status', PaymentRecordStatus::PENDING->value)->exists()) {
                continue;
            }
            $effects = new StockEffects;
            $cancelled = DB::transaction(function () use ($o, $effects) {
                $locked = $this->ledger->lockOrder($o->id);
                if ($locked->status !== OrderStatus::PENDING_PAYMENT->value || $locked->amount_paid > 0) {
                    return false;
                }
                $this->workflow->cancel($locked, 'Paiement non confirmé dans le délai imparti', null, $effects);
                Order::whereKey($o->id)->update(['payment_status' => PaymentStatus::FAILED->value]);

                return true;
            });
            $this->inventory->flush($effects);
            if ($cancelled) {
                DomainEvent::emit(DomainEvent::ORDER_CANCELLED, ['orderId' => $o->id]);
                $count++;
            }
        }

        return $count;
    }

    // ----- Administration ----------------------------------------------------------

    public function list(array $q): array
    {
        $qb = Payment::query()->select('payments.*')->join('orders as o', 'o.id', '=', 'payments.order_id')->with(['order', 'adminUser']);
        foreach (['provider' => 'provider', 'status' => 'status', 'orderId' => 'order_id'] as $k => $col) {
            if (! empty($q[$k])) {
                $qb->where("payments.{$col}", $q[$k]);
            }
        }
        if (! empty($q['from'])) {
            $qb->where('payments.created_at', '>=', Carbon::parse($q['from']));
        }
        if (! empty($q['to'])) {
            $qb->where('payments.created_at', '<=', Carbon::parse($q['to']));
        }
        if (! empty($q['duplicates'])) {
            $qb->where('payments.is_duplicate', true);
        }
        if (! empty($q['q'])) {
            $like = "%{$q['q']}%";
            $qb->where(fn ($w) => $w->where('o.number', 'like', $like)->orWhere('payments.provider_reference', 'like', $like)
                ->orWhere('payments.provider_transaction_id', 'like', $like)->orWhere('payments.payer_phone', 'like', $like));
        }
        $total = (clone $qb)->count();
        $rows = $qb->orderByDesc('payments.created_at')->forPage($q['page'], $q['limit'])->get();

        return Paginated::make($rows->map(fn ($p) => $p->attributesToArrayCamel() + [
            'order' => ['id' => $p->order->id, 'number' => $p->order->number, 'contactName' => $p->order->contact_name],
            'adminUser' => $p->adminUser?->full_name,
        ])->all(), $total, $q);
    }

    public function reconciliation(array $q): array
    {
        $from = ! empty($q['from']) ? Carbon::parse($q['from']) : now()->subDays(30);
        $to = ! empty($q['to']) ? Carbon::parse($q['to']) : now();
        $byProvider = DB::select('SELECT provider, status, COUNT(id) AS count, SUM(amount) AS amount, SUM(refunded_amount) AS refunded
            FROM payments WHERE created_at BETWEEN ? AND ? GROUP BY provider, status', [$from, $to]);
        $byDay = DB::select("SELECT DATE(paid_at) AS day, provider, SUM(amount) AS amount, COUNT(id) AS count
            FROM payments WHERE status IN ('SUCCEEDED','PARTIALLY_REFUNDED','REFUNDED') AND paid_at BETWEEN ? AND ?
            GROUP BY day, provider ORDER BY day ASC", [$from, $to]);
        $anomalies = PaymentEvent::whereIn('outcome', ['amount_mismatch', 'unknown_reference', 'invalid_signature', 'duplicate_payment'])
            ->whereBetween('created_at', [$from, $to])->orderByDesc('created_at')->get();
        $duplicates = Payment::with('order')->where('is_duplicate', true)->orderByDesc('created_at')->limit(100)->get();
        $mismatches = DB::select("SELECT o.id, o.number, o.amount_paid AS amountPaid, COALESCE(SUM(p.amount),0) AS paymentsTotal
            FROM orders o LEFT JOIN payments p ON p.order_id = o.id AND p.status IN ('SUCCEEDED','PARTIALLY_REFUNDED','REFUNDED') AND p.is_duplicate = 0
            WHERE o.created_at BETWEEN ? AND ? GROUP BY o.id, o.number, o.amount_paid HAVING o.amount_paid <> paymentsTotal LIMIT 100", [$from, $to]);

        return [
            'period' => ['from' => Format::iso($from), 'to' => Format::iso($to)],
            'byProvider' => array_map(fn ($r) => ['provider' => $r->provider, 'status' => $r->status, 'count' => (int) $r->count, 'amount' => (int) ($r->amount ?? 0), 'refunded' => (int) ($r->refunded ?? 0)], $byProvider),
            'byDay' => array_map(fn ($r) => ['day' => $r->day, 'provider' => $r->provider, 'amount' => (int) ($r->amount ?? 0), 'count' => (int) $r->count], $byDay),
            'anomalies' => $anomalies->map(fn ($a) => ['id' => $a->id, 'provider' => $a->provider, 'eventId' => $a->event_id, 'outcome' => $a->outcome, 'createdAt' => Format::iso($a->created_at), 'paymentId' => $a->payment_id])->all(),
            'duplicates' => $duplicates->map(fn ($d) => [
                'id' => $d->id, 'amount' => $d->amount, 'provider' => $d->provider, 'providerReference' => $d->provider_reference, 'orderId' => $d->order_id,
                'orderNumber' => $d->order->number, 'paidAt' => Format::iso($d->paid_at), 'refundedAmount' => $d->refunded_amount,
            ])->all(),
            'mismatches' => array_map(fn ($r) => ['id' => (int) $r->id, 'number' => $r->number, 'amountPaid' => (int) $r->amountPaid, 'paymentsTotal' => (int) $r->paymentsTotal], $mismatches),
        ];
    }

    /** Paiement reçu manuellement : réservé au super-admin, référence obligatoire, journalisé. */
    public function manual(int $orderId, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $res = DB::transaction(function () use ($orderId, $dto, $actor) {
            $order = $this->ledger->lockOrder($orderId);
            if ($order->status === OrderStatus::CANCELLED->value) {
                throw new HttpException(400, 'Commande annulée');
            }
            if ($dto['amount'] > $order->balance_due) {
                throw new HttpException(400, 'Le montant dépasse le reste à payer');
            }
            if (Payment::where('manual_reference', $dto['reference'])->exists()) {
                throw new HttpException(400, 'Cette référence de transaction a déjà été enregistrée');
            }

            return $this->ledger->recordOffline($order, [
                'amount' => $dto['amount'], 'method' => $dto['method'], 'provider' => 'manual', 'reference' => $dto['reference'], 'adminUserId' => $actor->id, 'isManual' => true,
            ]);
        });
        DomainEvent::emit(DomainEvent::PAYMENT_SUCCEEDED, ['paymentId' => $res['payment']->id, 'orderId' => $orderId]);
        $this->audit->log($actor->id, 'payment.manual', 'order', $orderId, null, $dto, $meta);

        return ['success' => true, 'paymentStatus' => $res['result']['order']->payment_status];
    }

    /** Remboursement total ou partiel (via l'opérateur ou enregistrement manuel justifié). */
    public function refund(int $orderId, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $amount = (int) $dto['amount'];
        $payment = Payment::where('order_id', $orderId)
            ->whereIn('status', [PaymentRecordStatus::SUCCEEDED->value, PaymentRecordStatus::PARTIALLY_REFUNDED->value])
            ->when(! empty($dto['paymentId']), fn ($q) => $q->where('id', $dto['paymentId']))
            ->orderByDesc('is_duplicate')->orderByDesc('paid_at')->get()
            ->first(fn ($p) => $p->amount - $p->refunded_amount >= $amount)
            ?? throw new HttpException(400, 'Aucun paiement ne permet de rembourser ce montant');
        $manual = ! empty($dto['manual']);
        if ($manual && empty($dto['manualReference'])) {
            throw new HttpException(400, 'Référence du remboursement manuel obligatoire');
        }

        $status = RefundStatus::SUCCEEDED->value;
        $providerReference = $dto['manualReference'] ?? null;
        if (! $manual) {
            if (in_array($payment->provider, ['cash', 'manual'], true)) {
                throw new HttpException(400, 'Paiement hors opérateur : enregistrez un remboursement manuel');
            }
            $result = $this->factory->byCode($payment->provider)->refund($payment, $amount);
            $status = $result['success'] ? RefundStatus::SUCCEEDED->value : RefundStatus::FAILED->value;
            $providerReference = $result['providerReference'] ?? null;
            if (! $result['success']) {
                Refund::create(['order_id' => $orderId, 'payment_id' => $payment->id, 'amount' => $amount, 'reason' => $dto['reason'], 'status' => $status, 'admin_user_id' => $actor->id]);
                throw new HttpException(400, "Remboursement refusé par l'opérateur".(! empty($result['message']) ? " : {$result['message']}" : ''));
            }
        }
        DB::transaction(function () use ($orderId, $payment, $amount, $dto, $status, $providerReference, $manual, $actor) {
            Refund::create([
                'order_id' => $orderId, 'payment_id' => $payment->id, 'amount' => $amount, 'reason' => $dto['reason'], 'status' => $status,
                'provider_reference' => $providerReference, 'is_manual' => $manual, 'manual_reference' => $dto['manualReference'] ?? null, 'admin_user_id' => $actor->id,
            ]);
            $refunded = $payment->refunded_amount + $amount;
            Payment::whereKey($payment->id)->update([
                'refunded_amount' => $refunded,
                'status' => $refunded >= $payment->amount ? PaymentRecordStatus::REFUNDED->value : PaymentRecordStatus::PARTIALLY_REFUNDED->value,
            ]);
            if (! $payment->is_duplicate) {
                $order = $this->ledger->lockOrder($orderId);
                $order->amount_refunded += $amount;
                $order->payment_status = OrderState::computePaymentStatus($order->total, $order->amount_paid, $order->amount_refunded, $order->payment_status, $order->payment_method);
                $patch = ['amount_refunded' => $order->amount_refunded, 'payment_status' => $order->payment_status];
                if ($order->status === OrderStatus::CANCELLED->value && $order->amount_refunded >= $order->amount_paid) {
                    $patch['status'] = OrderStatus::REFUNDED->value;
                }
                Order::whereKey($orderId)->update($patch);
            }
        });
        $this->audit->log($actor->id, 'payment.refund', 'order', $orderId, null, $dto + ['paymentId' => $payment->id], $meta);

        return ['success' => true];
    }
}
