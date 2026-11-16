<?php

namespace App\Services\Orders;

use App\Enums\DeliveryGrouping;
use App\Enums\DeliveryMode;
use App\Enums\ItemType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Events\DomainEvent;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductImage;
use App\Models\Shipment;
use App\Services\Auth\CustomerAuthService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockEffects;
use App\Services\NumberingService;
use App\Services\SettingsService;
use App\Support\Domain;
use App\Support\Format;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdersService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly InventoryService $inventory,
        private readonly OrderWorkflowService $workflow,
        private readonly NumberingService $numbering,
        private readonly SettingsService $settings,
        private readonly CustomerAuthService $customerAuth,
    ) {}

    /**
     * Création de commande avec réservation de stock transactionnelle (règle 6.2).
     *
     * @param  array{source: string, customerId?: ?int, ip?: ?string, adminUserId?: ?int, note?: ?string}  $opts
     */
    public function create(array $dto, array $opts): array
    {
        $isAdmin = $opts['source'] !== OrderSource::WEB->value;
        $contact = $dto['contact'];
        $delivery = $dto['delivery'];
        $phone = Format::normalizeGabonPhone($contact['phone']) ?? throw new HttpException(400, 'Numéro gabonais invalide (ex. +241 77 12 34 56)');
        if (empty($dto['cgvAccepted'])) {
            throw new HttpException(400, 'Vous devez accepter les conditions générales de vente');
        }
        $pickup = $this->settings->get('pickup');
        if ($delivery['mode'] === DeliveryMode::PICKUP->value && ! $pickup['enabled']) {
            throw new HttpException(400, "Le retrait en boutique n'est pas disponible");
        }
        if ($delivery['mode'] === DeliveryMode::HOME->value) {
            if (empty($delivery['zoneId'])) {
                throw new HttpException(400, 'Choisissez votre zone de livraison');
            }
            if (trim($delivery['landmark'] ?? '') === '') {
                throw new HttpException(400, 'Le point de repère est obligatoire');
            }
        }

        ['result' => $result, 'context' => $context] = $this->cart->validate([
            'items' => $dto['items'],
            'couponCode' => $dto['couponCode'] ?? null,
            'zoneId' => $delivery['mode'] === DeliveryMode::HOME->value ? $delivery['zoneId'] : null,
            'deliveryMode' => $delivery['mode'],
            'grouping' => $delivery['grouping'] ?? null,
            'phone' => $phone,
            'customerId' => $opts['customerId'] ?? null,
        ]);
        if ($context['blocking'] || ! $context['pricingLines']) {
            throw new HttpResponseException(response()->json([
                'statusCode' => 409,
                'message' => $result['issues'][0] ?? 'Votre panier a changé, veuillez le vérifier.',
                'errors' => $result['issues'],
            ], 409));
        }
        if (! empty($dto['couponCode']) && $result['couponError']) {
            throw new HttpException(400, $result['couponError']);
        }
        if ($delivery['mode'] === DeliveryMode::HOME->value && ! $context['zone']) {
            throw new HttpException(400, 'Zone de livraison invalide ou inactive');
        }
        if (! in_array($dto['paymentMethod'], $result['allowedPaymentMethods'], true)) {
            throw new HttpException(400, $result['hasPreorder'] && $dto['paymentMethod'] === PaymentMethod::CASH_ON_DELIVERY->value
                ? "Le paiement à la livraison n'est pas disponible pour les articles en précommande"
                : "Ce moyen de paiement n'est pas disponible");
        }
        $preorderTermsText = $result['hasPreorder']
            ? "J'ai compris que ces articles sont en précommande avec un délai estimé de {$result['preorderLeadTimeDays']} jours, susceptible de varier (transport, douane)."
            : null;
        if ($result['hasPreorder'] && empty($dto['preorderTermsAccepted'])) {
            throw new HttpException(400, 'Vous devez accepter les conditions de la précommande');
        }

        $now = now();
        $totals = $result['totals'];
        $grouping = $result['hasLocal'] && $result['hasPreorder'] ? ($delivery['grouping'] ?? DeliveryGrouping::SPLIT->value) : DeliveryGrouping::SPLIT->value;
        $isCod = $dto['paymentMethod'] === PaymentMethod::CASH_ON_DELIVERY->value;
        $effects = new StockEffects;
        $email = ! empty($contact['email']) ? strtolower($contact['email']) : null;

        $order = DB::transaction(function () use ($dto, $opts, $contact, $delivery, $phone, $email, $result, $context, $totals, $grouping, $isCod, $now, $preorderTermsText, $effects) {
            $customerId = $opts['customerId'] ?? null;
            if (! $customerId) {
                $existing = Customer::where('phone', $phone)->first();
                $customerId = $existing?->id ?? Customer::create([
                    'full_name' => trim($contact['fullName']), 'phone' => $phone, 'email' => $email, 'is_guest' => true,
                ])->id;
            }
            $zone = $context['zone'];
            $o = Order::create([
                'number' => $this->numbering->orderNumber(),
                'customer_id' => $customerId,
                'contact_name' => trim($contact['fullName']),
                'contact_phone' => $phone,
                'contact_email' => $email,
                'city' => $delivery['mode'] === DeliveryMode::HOME->value ? ($zone?->city ?? $delivery['city'] ?? null) : null,
                'zone_id' => $zone?->id,
                'zone_name' => $zone?->name,
                'district' => trim($delivery['district'] ?? '') ?: null,
                'landmark' => trim($delivery['landmark'] ?? '') ?: null,
                'instructions' => trim($delivery['instructions'] ?? '') ?: null,
                'delivery_mode' => $delivery['mode'],
                'delivery_grouping' => $grouping,
                'order_type' => $result['hasLocal'] && $result['hasPreorder'] ? 'MIXED' : ($result['hasPreorder'] ? 'PREORDER' : 'LOCAL'),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discountTotal'],
                'shipping_total' => $totals['shippingTotal'],
                'total' => $totals['total'],
                'deposit_amount' => $totals['depositAmount'],
                'amount_paid' => 0,
                'amount_refunded' => 0,
                'balance_due' => $totals['total'],
                'status' => OrderStatus::PENDING_PAYMENT->value,
                'payment_status' => $isCod ? PaymentStatus::AWAITING_CASH->value : PaymentStatus::UNPAID->value,
                'payment_method' => $dto['paymentMethod'],
                'coupon_id' => $context['coupon']?->id,
                'coupon_code' => $context['coupon']?->code,
                'preorder_terms_accepted_at' => $result['hasPreorder'] ? $now : null,
                'preorder_terms_text' => $preorderTermsText,
                'cgv_accepted_at' => $now,
                'tracking_token' => Format::randomToken(32),
                'source' => $opts['source'],
                'notes_internal' => ! empty($opts['note']) ? '['.Format::iso($now)."] {$opts['note']}" : null,
                'ip_address' => $opts['ip'] ?? null,
                'created_by_admin_id' => $opts['adminUserId'] ?? null,
            ]);

            // Expéditions : LOCAL / PREORDER, ou une seule si « tout recevoir ensemble »
            $mk = fn (string $type) => Shipment::create([
                'order_id' => $o->id, 'type' => $type, 'shipping_fee' => $context['feePerShipment'], 'delivery_code' => Format::randomDigits(4),
            ]);
            $shipmentFor = [];
            if ($result['hasLocal'] && $result['hasPreorder'] && $grouping === DeliveryGrouping::TOGETHER->value) {
                $single = $mk(ItemType::PREORDER->value);
                $shipmentFor = [ItemType::LOCAL->value => $single, ItemType::PREORDER->value => $single];
            } else {
                if ($result['hasLocal']) {
                    $shipmentFor[ItemType::LOCAL->value] = $mk(ItemType::LOCAL->value);
                }
                if ($result['hasPreorder']) {
                    $shipmentFor[ItemType::PREORDER->value] = $mk(ItemType::PREORDER->value);
                }
            }

            $imgs = ProductImage::whereIn('product_id', array_column($context['pricingLines'], 'productId'))->get();
            foreach ($context['pricingLines'] as $index => $line) {
                $v = $context['variants'][$line['variantId']];
                $img = $imgs->first(fn ($i) => $i->variant_id === $v->id)
                    ?? $imgs->first(fn ($i) => $i->product_id === $v->product_id && $i->is_main)
                    ?? $imgs->first(fn ($i) => $i->product_id === $v->product_id);
                OrderItem::create([
                    'order_id' => $o->id,
                    'variant_id' => $v->id,
                    'product_id' => $v->product_id,
                    'product_name' => $v->product->name,
                    'reference' => $v->sku,
                    'variant_name' => $v->name === 'Standard' ? null : $v->name,
                    'image_url' => $img?->thumb_url,
                    'unit_price' => $line['unitPrice'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['unitPrice'] * $line['quantity'],
                    'deposit_amount' => $context['lineDeposits'][$index],
                    'lead_time_days' => $line['leadTimeDays'],
                    'item_type' => $line['itemType'],
                    'shipment_id' => $shipmentFor[$line['itemType']]->id,
                    'stock_reserved' => $line['itemType'] === ItemType::LOCAL->value,
                ]);
            }

            $localLines = array_values(array_filter($context['pricingLines'], fn ($l) => $l['itemType'] === ItemType::LOCAL->value));
            if ($localLines) {
                $this->inventory->reserve(array_map(fn ($l) => [
                    'variantId' => $l['variantId'], 'quantity' => $l['quantity'], 'label' => $context['variants'][$l['variantId']]->product->name,
                ], $localLines), $o->id, $effects);
            }

            if ($context['coupon']) {
                CouponUsage::create(['coupon_id' => $context['coupon']->id, 'order_id' => $o->id, 'customer_id' => $customerId, 'phone' => $phone, 'discount' => $totals['discountTotal']]);
                Coupon::whereKey($context['coupon']->id)->increment('used_count');
            }

            if ($isCod || $totals['dueNow'] === 0) {
                if (! $isCod) {
                    $o->payment_status = PaymentStatus::PAID->value;
                }
                Order::whereKey($o->id)->update(['payment_status' => $o->payment_status]);
                $this->workflow->confirm($o, $now);
            }

            return $o;
        });

        $this->inventory->flush($effects);
        DomainEvent::emit(DomainEvent::ORDER_CREATED, ['orderId' => $order->id]);
        if (! empty($contact['createAccount']) && ! empty($contact['password']) && $order->customer_id && ! $isAdmin) {
            $this->customerAuth->upgradeGuest($order->customer_id, $contact['password'], $email);
        }

        return [
            'orderId' => $order->id,
            'number' => $order->number,
            'trackingToken' => $order->tracking_token,
            'status' => $order->status,
            'paymentStatus' => $order->payment_status,
            'total' => $order->total,
            'dueNow' => $isCod ? 0 : $totals['dueNow'],
        ];
    }

    /** Montant exigible au premier paiement mobile money (tout sauf le solde différé des précommandes). */
    public static function initialDue(Order $order, Collection|iterable $items): int
    {
        $deferred = 0;
        foreach ($items as $i) {
            if ($i->item_type === ItemType::PREORDER->value) {
                $deferred += $i->line_total - $i->deposit_amount;
            }
        }

        return max(0, $order->total - $deferred);
    }

    public function trackByNumber(string $number, string $phoneInput): array
    {
        $phone = Format::normalizeGabonPhone($phoneInput);
        $order = $phone ? Order::where('number', strtoupper($number))->where('contact_phone', $phone)->first() : null;
        if (! $order) {
            throw new HttpException(404, 'Aucune commande ne correspond à ce numéro et ce téléphone.');
        }

        return $this->toTracking($order->id);
    }

    public function trackByToken(string $token): array
    {
        $order = Order::where('tracking_token', $token)->first() ?? throw new HttpException(404, 'Lien de suivi invalide ou expiré.');

        return $this->toTracking($order->id);
    }

    /** Construit la vue publique : uniquement les étapes réellement confirmées. */
    public function toTracking(int $orderId): array
    {
        $order = Order::with(['shipments.events', 'shipments.items'])->findOrFail($orderId);
        $pending = Payment::where('order_id', $orderId)->where('status', PaymentRecordStatus::PENDING->value)->orderByDesc('created_at')->first();

        return [
            'number' => $order->number,
            'status' => $order->status,
            'paymentStatus' => $order->payment_status,
            'paymentMethod' => $order->payment_method,
            'deliveryMode' => $order->delivery_mode,
            'deliveryGrouping' => $order->delivery_grouping,
            'createdAt' => Format::iso($order->created_at),
            'customerFirstName' => explode(' ', $order->contact_name)[0],
            'subtotal' => $order->subtotal,
            'discountTotal' => $order->discount_total,
            'shippingTotal' => $order->shipping_total,
            'total' => $order->total,
            'depositAmount' => $order->deposit_amount,
            'amountPaid' => $order->amount_paid,
            'balanceDue' => $order->balance_due,
            'pendingPaymentId' => $pending?->id,
            'shipments' => $order->shipments->sortBy('id')->values()->map(fn ($s) => [
                'id' => $s->id,
                'type' => $s->type,
                'currentStep' => $s->current_step,
                'steps' => array_map(fn ($step) => ['step' => $step, 'label' => Domain::STEP_LABELS[$step]], Domain::stepSequence($s->type, $order->delivery_mode)),
                'estimatedDeliveryDate' => Format::iso($s->estimated_delivery_date),
                'isDelayed' => $s->is_delayed,
                'delayReason' => $s->is_delayed ? $s->delay_reason : null,
                'shippingFee' => $s->shipping_fee,
                'events' => $s->events->sortBy(fn ($e) => $e->occurred_at->valueOf())->values()->map(fn ($e) => [
                    'step' => $e->step,
                    'label' => Domain::STEP_LABELS[$e->step],
                    'occurredAt' => Format::iso($e->occurred_at),
                    'publicComment' => $e->public_comment,
                    'proofUrl' => $e->proof_url,
                ])->all(),
                'items' => $s->items->map(fn ($i) => ['name' => $i->product_name, 'variantName' => $i->variant_name, 'quantity' => $i->quantity, 'imageUrl' => $i->image_url])->all(),
            ])->all(),
        ];
    }
}
