<?php

namespace App\Services\Orders;

use App\Enums\ItemType;
use App\Enums\NotificationTemplateCode;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStep;
use App\Events\DomainEvent;
use App\Models\AuditLog;
use App\Models\CashCollection;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Services\AuditService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockEffects;
use App\Services\SettingsService;
use App\Support\AdminPrincipal;
use App\Support\Domain;
use App\Support\Format;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdersAdminService
{
    public function __construct(
        private readonly OrderWorkflowService $workflow,
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
    ) {}

    public function buildListQuery(array $q): Builder
    {
        $qb = Order::query();
        if (! empty($q['q'])) {
            $like = '%'.preg_replace('/\s/', '', trim($q['q'])).'%';
            $qb->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('contact_name', 'like', $like)
                ->orWhere('contact_phone', 'like', $like)->orWhere('contact_email', 'like', $like));
        }
        foreach (['status' => 'status', 'paymentStatus' => 'payment_status', 'paymentMethod' => 'payment_method', 'orderType' => 'order_type', 'zoneId' => 'zone_id'] as $k => $col) {
            if (! empty($q[$k])) {
                $qb->where($col, $q[$k]);
            }
        }
        if (! empty($q['from'])) {
            $qb->where('created_at', '>=', Carbon::parse($q['from']));
        }
        if (! empty($q['to'])) {
            $qb->where('created_at', '<=', Carbon::parse($q['to']));
        }
        if (! empty($q['driverId'])) {
            $qb->whereExists(fn ($s) => $s->select(DB::raw(1))->from('shipments as s')->whereColumn('s.order_id', 'orders.id')->where('s.driver_id', $q['driverId']));
        }
        switch ($q['preset'] ?? null) {
            case 'todo':
                $qb->whereIn('status', [OrderStatus::CONFIRMED->value, OrderStatus::IN_PROGRESS->value, OrderStatus::PARTIALLY_DELIVERED->value]);
                break;
            case 'delayed':
                $qb->whereExists(fn ($s) => $s->select(DB::raw(1))->from('shipments as s')->whereColumn('s.order_id', 'orders.id')
                    ->whereNotIn('s.current_step', ['DELIVERED', 'CANCELLED', 'RETURNED'])
                    ->where(fn ($w) => $w->where('s.is_delayed', true)->orWhere('s.estimated_delivery_date', '<', DB::raw('NOW()'))));
                break;
            case 'awaiting_cash':
                $qb->where('payment_status', PaymentStatus::AWAITING_CASH->value)->where('status', '<>', OrderStatus::CANCELLED->value);
                break;
            case 'pending_payment':
                $qb->where('status', OrderStatus::PENDING_PAYMENT->value);
                break;
        }

        return $qb;
    }

    public function list(array $q): array
    {
        $qb = $this->buildListQuery($q);
        $total = (clone $qb)->count();

        return Paginated::make($qb->orderByDesc('created_at')->forPage($q['page'], $q['limit'])->get()->toArray(), $total, $q);
    }

    private static function who($u): ?array
    {
        return $u ? ['id' => $u->id, 'fullName' => $u->full_name] : null;
    }

    public function detail(int $id): array
    {
        $order = Order::with(['customer', 'coupon', 'items', 'shipments.driver', 'shipments.events.adminUser'])->find($id)
            ?? throw new HttpException(404, 'Commande introuvable');
        $payments = Payment::where('order_id', $id)->orderBy('created_at')->get();
        $refunds = Refund::with('adminUser')->where('order_id', $id)->orderBy('created_at')->get();
        $cash = CashCollection::with('collectedBy')->where('order_id', $id)->get();
        $history = AuditLog::with('adminUser')->where('entity_type', 'order')->where('entity_id', (string) $id)->orderByDesc('created_at')->limit(100)->get();
        $conversations = Conversation::where('order_id', $id)->get(['id', 'status', 'last_message_at', 'visitor_name']);
        $notifications = Notification::where('order_id', $id)->orderByDesc('created_at')->limit(50)->get();
        $paymentEvents = $payments->isNotEmpty() ? PaymentEvent::whereIn('payment_id', $payments->pluck('id'))->orderBy('created_at')->get() : collect();
        $items = $order->items;
        $c = $order->customer;

        return $order->attributesToArrayCamel() + [
            'coupon' => $order->coupon?->toArray(),
            'items' => $items->map(fn ($i) => $i->toArray())->all(),
            'customer' => $c ? ['id' => $c->id, 'fullName' => $c->full_name, 'phone' => $c->phone, 'email' => $c->email, 'isGuest' => $c->is_guest] : null,
            'initialDue' => OrdersService::initialDue($order, $items),
            'shipments' => $order->shipments->sortBy('id')->values()->map(function ($s) use ($items, $order) {
                $seq = Domain::stepSequence($s->type, $order->delivery_mode);

                return $s->attributesToArrayCamel() + [
                    'driver' => $s->driver ? ['id' => $s->driver->id, 'fullName' => $s->driver->full_name, 'phone' => $s->driver->phone] : null,
                    'items' => $items->where('shipment_id', $s->id)->values()->map(fn ($i) => $i->toArray())->all(),
                    'steps' => array_map(fn ($step) => ['step' => $step, 'label' => Domain::STEP_LABELS[$step]], $seq),
                    'nextStep' => OrderState::nextStepOf($seq, $s->current_step),
                    'events' => $s->events->sortBy(fn ($e) => $e->occurred_at->valueOf())->values()->map(fn ($e) => $e->attributesToArrayCamel() + [
                        'label' => Domain::STEP_LABELS[$e->step],
                        'adminUser' => self::who($e->adminUser),
                    ])->all(),
                ];
            })->all(),
            'payments' => $payments->map(fn ($p) => $p->toArray() + ['events' => $paymentEvents->where('payment_id', $p->id)->values()->map(fn ($e) => $e->toArray())->all()])->all(),
            'refunds' => $refunds->map(fn ($r) => $r->attributesToArrayCamel() + ['adminUser' => self::who($r->adminUser)])->all(),
            'cashCollections' => $cash->map(fn ($x) => $x->attributesToArrayCamel() + ['collectedBy' => self::who($x->collectedBy)])->all(),
            'history' => $history->map(fn ($h) => $h->attributesToArrayCamel() + ['adminUser' => self::who($h->adminUser)])->all(),
            'conversations' => $conversations->toArray(),
            'notifications' => $notifications->toArray(),
        ];
    }

    public function confirm(int $id, AdminPrincipal $actor, RequestMeta $meta): array
    {
        DB::transaction(function () use ($id) {
            $order = Order::whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404, 'Commande introuvable');
            if ($order->status !== OrderStatus::PENDING_PAYMENT->value) {
                throw new HttpException(400, 'Cette commande est déjà confirmée ou clôturée');
            }
            $items = OrderItem::where('order_id', $id)->get();
            if ($order->payment_method !== PaymentMethod::CASH_ON_DELIVERY->value && $order->amount_paid < OrdersService::initialDue($order, $items)) {
                throw new HttpException(400, "Paiement non reçu : la commande ne peut pas être confirmée sans paiement validé par l'opérateur");
            }
            if ($order->payment_method === PaymentMethod::CASH_ON_DELIVERY->value && $order->amount_paid === 0) {
                Order::whereKey($id)->update(['payment_status' => PaymentStatus::AWAITING_CASH->value]);
            }
            $this->workflow->confirm($order, now());
        });
        $this->audit->log($actor->id, 'order.confirm', 'order', $id, null, null, $meta);

        return ['success' => true];
    }

    public function cancel(int $id, string $reason, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $effects = new StockEffects;
        $info = DB::transaction(function () use ($id, $reason, $actor, $effects) {
            $order = Order::whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404, 'Commande introuvable');
            $orderedFromSupplier = OrderItem::where('order_id', $id)->where('item_type', ItemType::PREORDER->value)->whereNotNull('arrival_id')->exists();
            $info = [
                'depositNonRefundable' => $orderedFromSupplier && ! $this->settings->get('payments')['depositRefundable'],
                'amountPaid' => $order->amount_paid,
            ];
            $this->workflow->cancel($order, $reason, $actor->id, $effects);

            return $info;
        });
        $this->inventory->flush($effects);
        DomainEvent::emit(DomainEvent::ORDER_CANCELLED, ['orderId' => $id]);
        $this->audit->log($actor->id, 'order.cancel', 'order', $id, null, ['reason' => $reason] + $info, $meta);

        return ['success' => true] + $info + [
            'message' => $info['depositNonRefundable']
                ? "Commande annulée. Les articles ont déjà été commandés au fournisseur : selon la politique d'annulation, l'acompte n'est pas remboursable."
                : ($info['amountPaid'] > 0 ? 'Commande annulée. Pensez à effectuer le remboursement depuis la fiche commande.' : 'Commande annulée et stock libéré.'),
        ];
    }

    public function addNote(int $id, string $note, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $order = Order::find($id) ?? throw new HttpException(404, 'Commande introuvable');
        $line = '['.Format::iso(now())."] {$actor->email} : ".trim($note);
        $order->update(['notes_internal' => $order->notes_internal ? "{$order->notes_internal}\n{$line}" : $line]);
        $this->audit->log($actor->id, 'order.note', 'order', $id, null, ['note' => $note], $meta);

        return ['success' => true];
    }

    public function resendNotification(int $id, string $template, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $order = Order::with('shipments')->find($id) ?? throw new HttpException(404, 'Commande introuvable');
        $latest = $order->shipments->sortByDesc(fn ($s) => $s->updated_at->valueOf())->first();
        switch ($template) {
            case NotificationTemplateCode::ORDER_CREATED->value:
                DomainEvent::emit(DomainEvent::ORDER_CREATED, ['orderId' => $id, 'resend' => true]);
                break;
            case NotificationTemplateCode::PAYMENT_CONFIRMED->value:
                $p = Payment::where('order_id', $id)->orderByDesc('paid_at')->first() ?? throw new HttpException(400, 'Aucun paiement enregistré');
                DomainEvent::emit(DomainEvent::PAYMENT_SUCCEEDED, ['paymentId' => $p->id, 'orderId' => $id]);
                break;
            case NotificationTemplateCode::SHIPMENT_STEP->value:
            case NotificationTemplateCode::OUT_FOR_DELIVERY->value:
            case NotificationTemplateCode::ORDER_DELIVERED->value:
                if (! $latest?->current_step) {
                    throw new HttpException(400, 'Aucune étape de suivi enregistrée');
                }
                DomainEvent::emit(DomainEvent::SHIPMENT_STEP, ['shipmentId' => $latest->id, 'step' => $latest->current_step]);
                break;
            case NotificationTemplateCode::SHIPMENT_DELAYED->value:
                if (! $latest?->is_delayed) {
                    throw new HttpException(400, 'Aucun retard signalé');
                }
                DomainEvent::emit(DomainEvent::SHIPMENT_DELAYED, ['shipmentId' => $latest->id, 'step' => ShipmentStep::DELAYED->value, 'publicComment' => $latest->delay_reason]);
                break;
            default:
                throw new HttpException(400, 'Ce gabarit ne peut pas être renvoyé depuis une commande');
        }
        $this->audit->log($actor->id, 'order.resend_notification', 'order', $id, null, ['template' => $template], $meta);

        return ['success' => true];
    }

    /** Bon de commande / bon de livraison imprimable (HTML autonome). */
    public function printable(int $id, string $kind): string
    {
        $order = Order::with('items')->find($id) ?? throw new HttpException(404, 'Commande introuvable');
        $shop = $this->settings->get('shop');
        $e = fn ($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES);
        $f = fn ($n) => Format::fcfa($n);
        $isDelivery = $kind === 'delivery';
        $title = $isDelivery ? 'Bon de livraison' : 'Bon de commande';
        $rows = $order->items->map(fn ($i) => '<tr><td>'.$e($i->product_name).($i->variant_name ? ' <small>('.$e($i->variant_name).')</small>' : '')
            .'<br><small>'.$e($i->reference).' · '.($i->item_type === ItemType::LOCAL->value ? 'Stock local' : 'Précommande').'</small></td>'
            ."<td class=\"r\">{$i->quantity}</td>"
            .($isDelivery ? '<td class="r">☐</td>' : '<td class="r">'.$f($i->unit_price).'</td><td class="r">'.$f($i->line_total).'</td>').'</tr>')->implode('');
        $totals = $isDelivery
            ? '<p><strong>Reste à encaisser :</strong> '.$f($order->balance_due).' ('.$e(Domain::label(Domain::PAYMENT_METHOD_LABELS, $order->payment_method)).')</p>'
            : '<table class="totals"><tr><td>Sous-total</td><td class="r">'.$f($order->subtotal).'</td></tr>'
                .'<tr><td>Remise</td><td class="r">-'.$f($order->discount_total).'</td></tr>'
                .'<tr><td>Livraison</td><td class="r">'.$f($order->shipping_total).'</td></tr>'
                .'<tr class="b"><td>Total</td><td class="r">'.$f($order->total).'</td></tr>'
                .'<tr><td>Déjà payé</td><td class="r">'.$f($order->amount_paid).'</td></tr>'
                .'<tr class="b"><td>Reste à payer</td><td class="r">'.$f($order->balance_due).'</td></tr></table>';
        $address = $order->delivery_mode === 'PICKUP' ? 'Retrait en boutique'
            : $e($order->city).' – '.$e($order->zone_name).($order->district ? ', '.$e($order->district) : '').'<br>Repère : '.$e($order->landmark)
                .($order->instructions ? '<br>Instructions : '.$e($order->instructions) : '');
        $itemHead = $isDelivery ? '<th class="r">Contrôle</th>' : '<th class="r">Prix unitaire</th><th class="r">Total</th>';

        return <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{$title} {$e($order->number)}</title>
<style>body{font-family:Montserrat,Arial,sans-serif;color:#2B2115;margin:32px;font-size:13px}h1{font-family:'Cormorant Garamond',Georgia,serif;color:#9A6712;letter-spacing:.08em;margin:0}
table{width:100%;border-collapse:collapse;margin-top:16px}th,td{padding:8px;border-bottom:1px solid #F2E9D6;text-align:left;vertical-align:top}.r{text-align:right}
.totals{width:320px;margin-left:auto}.b td{font-weight:700}.box{background:#FFFFFF;border:1px solid #F2E9D6;border-radius:12px;padding:12px;margin-top:16px}
.head{display:flex;justify-content:space-between;align-items:flex-start}.sign{margin-top:48px;display:flex;justify-content:space-between}@media print{body{margin:12mm}}</style></head>
<body onload="window.print()"><div class="head"><div><h1>{$e($shop['name'])}</h1><div>{$e($shop['tagline'])}</div><div>{$e($shop['address'])} · {$e(Format::phone($shop['phone']))}</div></div>
<div class="r"><h2>{$title}</h2><div><strong>{$e($order->number)}</strong></div><div>{$e(Format::date($order->created_at, true))}</div>
<div>{$e(Domain::label(Domain::ORDER_STATUS_LABELS, $order->status))} · {$e(Domain::label(Domain::PAYMENT_STATUS_LABELS, $order->payment_status))}</div></div></div>
<div class="box"><strong>{$e($order->contact_name)}</strong> · {$e(Format::phone($order->contact_phone))}{$this->optEmail($order->contact_email)}<br>
{$address}</div>
<table><thead><tr><th>Article</th><th class="r">Qté</th>{$itemHead}</tr></thead><tbody>{$rows}</tbody></table>
{$totals}{$this->signatures($isDelivery)}</body></html>
HTML;
    }

    private function optEmail(?string $email): string
    {
        return $email ? ' · '.htmlspecialchars($email, ENT_QUOTES) : '';
    }

    private function signatures(bool $isDelivery): string
    {
        return $isDelivery ? '<div class="sign"><div>Signature du livreur</div><div>Signature du client</div></div>' : '';
    }
}
