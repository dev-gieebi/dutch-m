<?php

namespace App\Services\Orders;

use App\Enums\DeliveryMode;
use App\Enums\ItemType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStep;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockEffects;
use Carbon\CarbonInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Transitions de commande partagées (confirmation, annulation, statut global). À appeler dans une transaction. */
class OrderWorkflowService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /** Confirme la commande : étape ORDER_CONFIRMED sur chaque expédition et dates estimées (règle 6.3). */
    public function confirm(Order $order, CarbonInterface $at): void
    {
        $shipments = Shipment::where('order_id', $order->id)->get();
        $items = OrderItem::where('order_id', $order->id)->get();
        $zone = $order->zone_id ? DeliveryZone::find($order->zone_id) : null;
        $localDays = $order->delivery_mode === DeliveryMode::PICKUP->value ? 1 : ($zone?->estimated_days ?? 2);
        foreach ($shipments as $s) {
            $lead = max([0, ...$items->where('shipment_id', $s->id)->where('item_type', ItemType::PREORDER->value)->pluck('lead_time_days')->all()]);
            $estimate = $s->type === ItemType::PREORDER->value ? $at->copy()->addDays($lead + $localDays) : $at->copy()->addDays($localDays);
            $patch = ['estimated_delivery_date' => $estimate];
            if (! $s->current_step) {
                $patch['current_step'] = ShipmentStep::ORDER_CONFIRMED->value;
                ShipmentEvent::create([
                    'shipment_id' => $s->id,
                    'step' => ShipmentStep::ORDER_CONFIRMED->value,
                    'public_comment' => 'Votre commande est confirmée. Merci pour votre confiance !',
                    'occurred_at' => $at,
                ]);
            }
            Shipment::whereKey($s->id)->update($patch);
        }
        $order->forceFill(['status' => OrderStatus::CONFIRMED->value, 'confirmed_at' => $at]);
        Order::whereKey($order->id)->update(['status' => OrderStatus::CONFIRMED->value, 'confirmed_at' => $at]);
    }

    /** Annule la commande : libère le stock réservé, clôture les expéditions, restitue le code promo. */
    public function cancel(Order $order, string $reason, ?int $adminUserId, StockEffects $effects): void
    {
        if (in_array($order->status, [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value], true)) {
            throw new HttpException(400, 'Commande déjà annulée');
        }
        if ($order->status === OrderStatus::DELIVERED->value) {
            throw new HttpException(400, 'Une commande livrée ne peut pas être annulée');
        }
        $reserved = OrderItem::where('order_id', $order->id)->where('stock_reserved', true)->whereNotNull('variant_id')->get();
        if ($reserved->isNotEmpty()) {
            $this->inventory->release(
                $reserved->map(fn ($i) => ['variantId' => $i->variant_id, 'quantity' => $i->quantity])->all(),
                ['orderId' => $order->id, 'adminUserId' => $adminUserId],
                "Annulation commande {$order->number}",
                $effects,
            );
            OrderItem::whereIn('id', $reserved->pluck('id'))->update(['stock_reserved' => false]);
        }
        foreach (Shipment::where('order_id', $order->id)->get() as $s) {
            if (in_array($s->current_step, [ShipmentStep::DELIVERED->value, ShipmentStep::CANCELLED->value], true)) {
                continue;
            }
            Shipment::whereKey($s->id)->update(['current_step' => ShipmentStep::CANCELLED->value]);
            ShipmentEvent::create([
                'shipment_id' => $s->id,
                'step' => ShipmentStep::CANCELLED->value,
                'public_comment' => 'Votre commande a été annulée.',
                'internal_note' => $reason,
                'admin_user_id' => $adminUserId,
                'occurred_at' => now(),
            ]);
        }
        if ($order->coupon_id) {
            $usage = CouponUsage::where('order_id', $order->id)->first();
            if ($usage) {
                $usage->delete();
                Coupon::whereKey($order->coupon_id)->decrement('used_count');
            }
        }
        $patch = ['status' => OrderStatus::CANCELLED->value, 'cancelled_reason' => mb_substr($reason, 0, 500), 'cancelled_at' => now()];
        if (in_array($order->payment_status, [PaymentStatus::PENDING->value, PaymentStatus::UNPAID->value, PaymentStatus::AWAITING_CASH->value], true) && $order->amount_paid === 0) {
            $patch['payment_status'] = $order->payment_status === PaymentStatus::PENDING->value ? PaymentStatus::FAILED->value : $order->payment_status;
        }
        $order->forceFill($patch);
        Order::whereKey($order->id)->update($patch);
    }

    public function refreshOrderStatus(Order $order): string
    {
        $steps = Shipment::where('order_id', $order->id)->pluck('current_step')->all();
        $status = OrderState::computeOrderStatus($order->status, $steps);
        if ($status !== $order->status) {
            $patch = ['status' => $status];
            if ($status === OrderStatus::DELIVERED->value) {
                $patch['delivered_at'] = now();
            }
            Order::whereKey($order->id)->update($patch);
            $order->forceFill($patch);
        }

        return $status;
    }
}
