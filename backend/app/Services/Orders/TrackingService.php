<?php

namespace App\Services\Orders;

use App\Enums\ItemType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\RoleCode;
use App\Enums\ShipmentStep;
use App\Events\DomainEvent;
use App\Models\AdminUser;
use App\Models\CashCollection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\AuditService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockEffects;
use App\Support\AdminPrincipal;
use App\Support\Domain;
use App\Support\Format;
use App\Support\RequestMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TrackingService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly OrderWorkflowService $workflow,
        private readonly PaymentLedgerService $ledger,
        private readonly AuditService $audit,
    ) {}

    /** @return array{shipment: Shipment, order: Order} */
    private function lockShipment(int $id): array
    {
        $s = Shipment::whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404, 'Expédition introuvable');

        return ['shipment' => $s, 'order' => Order::findOrFail($s->order_id)];
    }

    /**
     * Applique une étape à une expédition (dans une transaction existante).
     * Effets : stock (livraison, annulation, retour), statut de commande. Renvoie l'événement à notifier.
     *
     * @param  array{step: string, publicComment?: ?string, internalNote?: ?string, proofUrl?: ?string, adminUserId: ?int, arrivalId?: ?int, force?: bool}  $input
     */
    public function applyStep(Shipment $shipment, Order $order, array $input, StockEffects $effects): array
    {
        $step = $input['step'];
        if ($order->status === OrderStatus::CANCELLED->value && $step !== ShipmentStep::CANCELLED->value) {
            throw new HttpException(400, 'Cette commande est annulée');
        }
        if ($order->status === OrderStatus::PENDING_PAYMENT->value) {
            throw new HttpException(400, "Cette commande n'est pas encore confirmée (paiement en attente)");
        }
        OrderState::assertNextStep(Domain::stepSequence($shipment->type, $order->delivery_mode), $shipment->current_step, $step, (bool) ($input['force'] ?? false));

        $now = now();
        $items = OrderItem::where('shipment_id', $shipment->id)->get();
        $ctx = ['orderId' => $order->id, 'adminUserId' => $input['adminUserId']];
        $patch = ['current_step' => $step];
        $reserved = $items->filter(fn ($i) => $i->stock_reserved && $i->variant_id);
        $lines = $reserved->map(fn ($i) => ['variantId' => $i->variant_id, 'quantity' => $i->quantity])->values()->all();

        if ($step === ShipmentStep::DELIVERED->value) {
            if ($lines) {
                $this->inventory->convert($lines, $ctx, $effects);
                OrderItem::whereIn('id', $reserved->pluck('id'))->update(['stock_reserved' => false]);
            }
            foreach ($items as $i) {
                if ($i->product_id) {
                    Product::withTrashed()->whereKey($i->product_id)->increment('sales_count', $i->quantity);
                }
            }
            $patch['delivered_at'] = $now;
            $patch['is_delayed'] = false;
        }
        if ($step === ShipmentStep::CANCELLED->value && $lines) {
            $this->inventory->release($lines, $ctx, "Annulation expédition {$order->number}", $effects);
            OrderItem::whereIn('id', $reserved->pluck('id'))->update(['stock_reserved' => false]);
        }
        if ($step === ShipmentStep::RETURNED->value && $shipment->current_step === ShipmentStep::DELIVERED->value) {
            foreach ($items->filter(fn ($x) => $x->variant_id) as $i) {
                $this->inventory->receive(['variantId' => $i->variant_id, 'quantity' => $i->quantity], $ctx, "Retour client {$order->number}", false, $effects);
            }
        }

        Shipment::whereKey($shipment->id)->update($patch);
        $shipment->forceFill($patch);
        ShipmentEvent::create([
            'shipment_id' => $shipment->id,
            'step' => $step,
            'public_comment' => trim((string) ($input['publicComment'] ?? '')) ?: null,
            'internal_note' => trim((string) ($input['internalNote'] ?? '')) ?: null,
            'proof_url' => $input['proofUrl'] ?? null,
            'admin_user_id' => $input['adminUserId'],
            'arrival_id' => $input['arrivalId'] ?? null,
            'occurred_at' => $now,
        ]);
        $this->workflow->refreshOrderStatus($order);

        return ['shipmentId' => $shipment->id, 'step' => $step, 'publicComment' => $input['publicComment'] ?? null];
    }

    public function recordStep(int $shipmentId, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $force = ! empty($dto['force']);
        if ($force && (! $actor->isManager() || trim($dto['justification'] ?? '') === '')) {
            throw new HttpException(403, 'Seul un super-administrateur ou un gérant peut sauter une étape, avec une justification');
        }
        $effects = new StockEffects;
        $event = DB::transaction(function () use ($shipmentId, $dto, $actor, $force, $effects) {
            ['shipment' => $shipment, 'order' => $order] = $this->lockShipment($shipmentId);
            $note = implode("\n", array_filter([$dto['internalNote'] ?? null, $force ? "Saut d'étape justifié : {$dto['justification']}" : null]));

            return $this->applyStep($shipment, $order, [
                'step' => $dto['step'],
                'publicComment' => $dto['publicComment'] ?? null,
                'internalNote' => $note ?: null,
                'proofUrl' => $dto['proofUrl'] ?? null,
                'adminUserId' => $actor->id,
                'force' => $force,
            ], $effects);
        });
        $this->inventory->flush($effects);
        DomainEvent::emit(DomainEvent::SHIPMENT_STEP, $event);
        $this->audit->log($actor->id, $force ? 'shipment.step_forced' : 'shipment.step', 'shipment', $shipmentId, null, $dto, $meta);

        return ['success' => true, 'step' => $dto['step'], 'label' => Domain::STEP_LABELS[$dto['step']]];
    }

    public function delay(int $shipmentId, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        DB::transaction(function () use ($shipmentId, $dto, $actor) {
            ['shipment' => $shipment] = $this->lockShipment($shipmentId);
            if (in_array($shipment->current_step, [ShipmentStep::DELIVERED->value, ShipmentStep::CANCELLED->value], true)) {
                throw new HttpException(400, 'Cette expédition est clôturée');
            }
            Shipment::whereKey($shipmentId)->update(['is_delayed' => true, 'delay_reason' => $dto['reason'], 'estimated_delivery_date' => Carbon::parse($dto['newEstimatedDate'])]);
            ShipmentEvent::create([
                'shipment_id' => $shipmentId, 'step' => ShipmentStep::DELAYED->value, 'public_comment' => $dto['reason'],
                'admin_user_id' => $actor->id, 'occurred_at' => now(),
            ]);
        });
        DomainEvent::emit(DomainEvent::SHIPMENT_DELAYED, ['shipmentId' => $shipmentId, 'step' => ShipmentStep::DELAYED->value, 'publicComment' => $dto['reason']]);
        $this->audit->log($actor->id, 'shipment.delay', 'shipment', $shipmentId, null, $dto, $meta);

        return ['success' => true];
    }

    public function assignDriver(int $shipmentId, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $shipment = Shipment::find($shipmentId) ?? throw new HttpException(404, 'Expédition introuvable');
        if (! empty($dto['driverId'])) {
            $driver = AdminUser::where('id', $dto['driverId'])->where('is_active', true)->first();
            if (! $driver || ! in_array(RoleCode::LIVREUR->value, $driver->role_codes ?? [], true)) {
                throw new HttpException(400, "Cet utilisateur n'est pas un livreur actif");
            }
        }
        $before = ['driverId' => $shipment->driver_id];
        $shipment->update([
            'driver_id' => $dto['driverId'] ?? null,
            'scheduled_for' => ! empty($dto['scheduledFor']) ? Carbon::parse($dto['scheduledFor']) : $shipment->scheduled_for,
        ]);
        $this->audit->log($actor->id, 'shipment.assign_driver', 'shipment', $shipmentId, $before, $dto, $meta);

        return ['success' => true];
    }

    public function regenerateCode(int $shipmentId, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $code = Format::randomDigits(4);
        if (! Shipment::whereKey($shipmentId)->update(['delivery_code' => $code])) {
            throw new HttpException(404, 'Expédition introuvable');
        }
        $this->audit->log($actor->id, 'shipment.regenerate_code', 'shipment', $shipmentId, null, null, $meta);

        return ['deliveryCode' => $code];
    }

    /** Montant restant à encaisser pour une expédition donnée. */
    public function amountToCollect(Order $order, Shipment $shipment, iterable $items, int $openShipments): int
    {
        if ($order->balance_due <= 0) {
            return 0;
        }
        if ($openShipments <= 1) {
            return $order->balance_due;
        }
        $own = collect($items)->where('shipment_id', $shipment->id)->sum('line_total') + $shipment->shipping_fee;

        return min($order->balance_due, $own);
    }

    /** @param array{amount: int, method: string, reference?: ?string} $dto */
    public function recordCash(Order $order, int $shipmentId, array $dto, int $adminUserId): array
    {
        CashCollection::create([
            'shipment_id' => $shipmentId, 'order_id' => $order->id, 'amount' => $dto['amount'], 'method' => $dto['method'],
            'collected_by_id' => $adminUserId, 'collected_at' => now(), 'reference' => $dto['reference'] ?? null,
        ]);
        $method = $dto['method'] === 'CASH' ? PaymentMethod::CASH_ON_DELIVERY->value : $dto['method'];

        return $this->ledger->recordOffline($order, ['amount' => $dto['amount'], 'method' => $method, 'provider' => 'cash', 'reference' => $dto['reference'] ?? null, 'adminUserId' => $adminUserId]);
    }

    /** Encaissement saisi depuis la fiche commande. */
    public function collectCash(int $orderId, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $r = DB::transaction(function () use ($orderId, $dto, $actor) {
            $order = $this->ledger->lockOrder($orderId);
            if ($order->status === OrderStatus::CANCELLED->value) {
                throw new HttpException(400, 'Commande annulée');
            }
            if ($dto['amount'] > $order->balance_due) {
                throw new HttpException(400, "Le montant dépasse le reste à payer ({$order->balance_due} FCFA)");
            }
            $shipment = ! empty($dto['shipmentId'])
                ? Shipment::where('id', $dto['shipmentId'])->where('order_id', $orderId)->first()
                : Shipment::where('order_id', $orderId)->orderBy('id')->first();
            if (! $shipment) {
                throw new HttpException(400, 'Expédition introuvable');
            }

            return $this->recordCash($order, $shipment->id, $dto, $actor->id);
        });
        if (! $r['result']['duplicate']) {
            DomainEvent::emit(DomainEvent::PAYMENT_SUCCEEDED, ['paymentId' => $r['payment']->id, 'orderId' => $orderId]);
        }
        $this->audit->log($actor->id, 'payment.cash_collected', 'order', $orderId, null, $dto, $meta);

        return ['success' => true, 'paymentStatus' => $r['result']['order']->payment_status, 'balanceDue' => $r['result']['order']->balance_due];
    }

    // ----- Livreur --------------------------------------------------------------

    /** @return array{0: Carbon, 1: Carbon} */
    private function dayRange(?string $date): array
    {
        $start = ($date ? Carbon::parse($date) : now())->copy()->startOfDay();

        return [$start, $start->copy()->endOfDay()];
    }

    public function driverDeliveries(AdminPrincipal $actor, ?string $date): array
    {
        [$start, $end] = $this->dayRange($date);
        $shipments = Shipment::with(['order', 'items'])
            ->where('driver_id', $actor->id)
            ->where(fn ($q) => $q->whereIn('current_step', [ShipmentStep::OUT_FOR_DELIVERY->value, ShipmentStep::READY_FOR_PICKUP->value, ShipmentStep::PREPARING->value, ShipmentStep::RECEIVED_IN_STOCK->value])
                ->orWhereBetween('delivered_at', [$start, $end]))
            ->orderBy('scheduled_for')->orderBy('id')->get();

        return $this->serializeDeliveries($shipments);
    }

    private function serializeDeliveries(Collection $shipments): array
    {
        $orderIds = $shipments->pluck('order_id')->unique()->values()->all();
        $all = $orderIds ? Shipment::whereIn('order_id', $orderIds)->get() : collect();
        $allItems = $orderIds ? OrderItem::whereIn('order_id', $orderIds)->get() : collect();

        return $shipments->map(function (Shipment $s) use ($all, $allItems) {
            $o = $s->order;
            $open = $all->filter(fn ($x) => $x->order_id === $s->order_id && ! in_array($x->current_step, [ShipmentStep::DELIVERED->value, ShipmentStep::CANCELLED->value], true))->count();
            $query = implode(', ', array_filter([$o->landmark, $o->district, $o->zone_name, $o->city, 'Gabon']));

            return [
                'shipmentId' => $s->id,
                'type' => $s->type,
                'currentStep' => $s->current_step,
                'currentStepLabel' => $s->current_step ? Domain::STEP_LABELS[$s->current_step] : null,
                'scheduledFor' => Format::iso($s->scheduled_for),
                'deliveredAt' => Format::iso($s->delivered_at),
                'orderId' => $s->order_id,
                'orderNumber' => $o->number,
                'customerName' => $o->contact_name,
                'phone' => $o->contact_phone,
                'city' => $o->city,
                'zone' => $o->zone_name,
                'district' => $o->district,
                'landmark' => $o->landmark,
                'instructions' => $o->instructions,
                'deliveryMode' => $o->delivery_mode,
                'paymentMethod' => $o->payment_method,
                'amountToCollect' => $s->current_step === ShipmentStep::DELIVERED->value ? 0 : $this->amountToCollect($o, $s, $allItems, $open),
                'items' => $s->items->map(fn ($i) => ['name' => $i->product_name, 'variantName' => $i->variant_name, 'quantity' => $i->quantity])->all(),
                'mapsUrl' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query),
            ];
        })->all();
    }

    public function planning(?string $date): array
    {
        [$start, $end] = $this->dayRange($date);
        $shipments = Shipment::with(['order', 'items', 'driver'])
            ->where(fn ($q) => $q->whereBetween('scheduled_for', [$start, $end])
                ->orWhereIn('current_step', [ShipmentStep::OUT_FOR_DELIVERY->value, ShipmentStep::READY_FOR_PICKUP->value])
                ->orWhere(fn ($w) => $w->whereIn('current_step', [ShipmentStep::PREPARING->value, ShipmentStep::RECEIVED_IN_STOCK->value])->whereNotNull('driver_id')))
            ->orderBy('scheduled_for')->get();
        $rows = $this->serializeDeliveries($shipments);
        $groups = [];
        foreach ($shipments->values() as $i => $s) {
            $key = (string) ($s->driver_id ?? 'none');
            $groups[$key] ??= ['driverId' => $s->driver_id, 'driverName' => $s->driver?->full_name ?? 'Non affecté', 'deliveries' => []];
            $groups[$key]['deliveries'][] = $rows[$i];
        }

        return array_values($groups);
    }

    /** Validation de la remise par code à 4 chiffres (règle 6.7). */
    public function completeDelivery(int $shipmentId, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $effects = new StockEffects;
        $paymentEvent = null;
        $event = DB::transaction(function () use ($shipmentId, $dto, $actor, $effects, &$paymentEvent) {
            ['shipment' => $shipment, 'order' => $order] = $this->lockShipment($shipmentId);
            if ($shipment->driver_id !== $actor->id && ! $actor->can('delivery.write')) {
                throw new HttpException(403, 'Cette livraison ne vous est pas attribuée');
            }
            if (! in_array($shipment->current_step, [ShipmentStep::OUT_FOR_DELIVERY->value, ShipmentStep::READY_FOR_PICKUP->value], true)) {
                throw new HttpException(400, "Cette expédition n'est pas en cours de livraison");
            }
            if (! hash_equals((string) $shipment->delivery_code, (string) $dto['code'])) {
                throw new HttpException(400, 'Code de remise incorrect');
            }
            $allShipments = Shipment::where('order_id', $order->id)->get();
            $items = OrderItem::where('order_id', $order->id)->get();
            $open = $allShipments->filter(fn ($x) => ! in_array($x->current_step, [ShipmentStep::DELIVERED->value, ShipmentStep::CANCELLED->value], true))->count();
            $due = $this->amountToCollect($order, $shipment, $items, $open);
            if ($due > 0) {
                if (empty($dto['cashAmount']) || empty($dto['cashMethod'])) {
                    throw new HttpException(400, "Encaissement requis : {$due} FCFA à percevoir");
                }
                if ($dto['cashAmount'] > $order->balance_due) {
                    throw new HttpException(400, 'Le montant encaissé dépasse le reste à payer');
                }
                $r = $this->recordCash($order, $shipment->id, ['amount' => (int) $dto['cashAmount'], 'method' => $dto['cashMethod'], 'reference' => $dto['cashReference'] ?? null], $actor->id);
                if (! $r['result']['duplicate']) {
                    $paymentEvent = ['paymentId' => $r['payment']->id, 'orderId' => $order->id];
                }
                $order = $r['result']['order'];
            }

            return $this->applyStep($shipment, $order, [
                'step' => ShipmentStep::DELIVERED->value,
                'publicComment' => $dto['comment'] ?? 'Votre commande a été remise. Merci et à bientôt chez DutCh’M !',
                'proofUrl' => $dto['proofUrl'] ?? null,
                'adminUserId' => $actor->id,
            ], $effects);
        });
        $this->inventory->flush($effects);
        if ($paymentEvent) {
            DomainEvent::emit(DomainEvent::PAYMENT_SUCCEEDED, $paymentEvent);
        }
        DomainEvent::emit(DomainEvent::SHIPMENT_STEP, $event);
        $this->audit->log($actor->id, 'shipment.delivered_with_code', 'shipment', $shipmentId, null, array_merge($dto, ['code' => '****']), $meta);

        return ['success' => true];
    }

    public static function shipmentTypeLabel(string $type): string
    {
        return $type === ItemType::LOCAL->value ? 'Disponible à Libreville' : 'Sur commande';
    }
}
