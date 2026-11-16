<?php

namespace App\Listeners;

use App\Enums\AdminNotificationType as A;
use App\Enums\AvailabilityMode;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationTemplateCode as T;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStep;
use App\Events\DomainEvent;
use App\Models\BackInStockAlert;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Services\Catalog\Availability;
use App\Services\Notifications\NotificationsService;
use App\Services\Realtime;
use App\Support\Domain;
use App\Support\Format;
use Illuminate\Support\Facades\Log;

/** Traduit les événements métier en notifications client, internes et temps réel. */
class NotificationsListener
{
    public function __construct(
        private readonly NotificationsService $notifications,
        private readonly Realtime $realtime,
    ) {}

    public function handle(DomainEvent $event): void
    {
        $handlers = [
            DomainEvent::ORDER_CREATED => 'onOrderCreated',
            DomainEvent::ORDER_CANCELLED => 'onOrderCancelled',
            DomainEvent::PAYMENT_SUCCEEDED => 'onPaymentSucceeded',
            DomainEvent::PAYMENT_FAILED => 'onPaymentFailed',
            DomainEvent::PAYMENT_DUPLICATE => 'onPaymentDuplicate',
            DomainEvent::SHIPMENT_STEP => 'onShipmentStep',
            DomainEvent::SHIPMENT_DELAYED => 'onShipmentDelayed',
            DomainEvent::STOCK_LOW => 'onStockLow',
            DomainEvent::STOCK_BACK => 'onStockBack',
            DomainEvent::ARRIVAL_MISSING => 'onArrivalMissing',
            DomainEvent::CONVERSATION_CUSTOMER_MESSAGE => 'onCustomerMessage',
            DomainEvent::CONVERSATION_ADMIN_REPLY => 'onAdminReply',
            DomainEvent::AUTH_PASSWORD_RESET => 'onPasswordReset',
            DomainEvent::AUTH_PHONE_VERIFICATION => 'onPhoneVerification',
        ];
        $method = $handlers[$event->name] ?? null;
        if (! $method) {
            return;
        }
        try {
            $this->{$method}($event->payload);
        } catch (\Throwable $e) {
            Log::error("Notification « {$event->name} » impossible : {$e->getMessage()}");
        }
    }

    private function trackingUrl(Order $o): string
    {
        return config('amoria.web_public_url')."/suivi/{$o->tracking_token}";
    }

    private function to(Order $o): array
    {
        return ['name' => $o->contact_name, 'email' => $o->contact_email, 'phone' => $o->contact_phone];
    }

    private function onOrderCreated(array $e): void
    {
        $o = Order::findOrFail($e['orderId']);
        $instructions = $o->payment_method === PaymentMethod::CASH_ON_DELIVERY->value
            ? 'Vous réglerez '.Format::fcfa($o->balance_due).' à la livraison (espèces ou mobile money).'
            : 'Paiement par '.Domain::label(Domain::PAYMENT_METHOD_LABELS, $o->payment_method).' : validez la demande reçue sur votre téléphone.';
        $this->notifications->notifyCustomer(T::ORDER_CREATED->value, $this->to($o),
            ['orderNumber' => $o->number, 'total' => Format::fcfa($o->total), 'trackingUrl' => $this->trackingUrl($o), 'paymentInstructions' => $instructions],
            ['orderId' => $o->id]);
        if (! empty($e['resend'])) {
            return;
        }
        $this->notifications->notifyAdmins(A::NEW_ORDER->value, "Nouvelle commande {$o->number} – ".Format::fcfa($o->total), "/commandes/{$o->id}");
        $this->realtime->emitToAdmins('order.created', ['id' => $o->id, 'number' => $o->number, 'total' => $o->total, 'contactName' => $o->contact_name, 'status' => $o->status]);
    }

    private function onOrderCancelled(array $e): void
    {
        $this->realtime->emitToAdmins('order.updated', ['id' => $e['orderId']]);
    }

    private function onPaymentSucceeded(array $e): void
    {
        $p = Payment::with('order')->findOrFail($e['paymentId']);
        $o = $p->order;
        $this->notifications->notifyCustomer(T::PAYMENT_CONFIRMED->value, $this->to($o),
            ['orderNumber' => $o->number, 'amount' => Format::fcfa($p->amount), 'balanceDue' => Format::fcfa($o->balance_due), 'trackingUrl' => $this->trackingUrl($o)],
            ['orderId' => $o->id]);
        $this->notifications->notifyAdmins(A::PAYMENT_RECEIVED->value, 'Paiement reçu : '.Format::fcfa($p->amount)." – {$o->number}", "/commandes/{$o->id}");
        $this->realtime->emitToAdmins('payment.updated', ['paymentId' => $p->id, 'orderId' => $o->id, 'status' => $p->status]);
    }

    private function onPaymentFailed(array $e): void
    {
        $p = Payment::with('order')->findOrFail($e['paymentId']);
        $this->notifications->notifyCustomer(T::PAYMENT_FAILED->value, $this->to($p->order),
            ['orderNumber' => $p->order->number, 'reason' => $p->failure_reason ?? 'non validé', 'trackingUrl' => $this->trackingUrl($p->order)],
            ['orderId' => $p->order_id]);
        $this->realtime->emitToAdmins('payment.updated', ['paymentId' => $p->id, 'orderId' => $p->order_id, 'status' => $p->status]);
    }

    private function onPaymentDuplicate(array $e): void
    {
        $p = Payment::with('order')->findOrFail($e['paymentId']);
        $this->notifications->notifyAdmins(A::DUPLICATE_PAYMENT->value,
            'Paiement en double ('.Format::fcfa($p->amount).") sur {$p->order->number} : remboursement à prévoir", "/commandes/{$p->order_id}");
        $this->realtime->emitToAdmins('payment.updated', ['paymentId' => $p->id, 'orderId' => $p->order_id, 'status' => $p->status, 'duplicate' => true]);
    }

    private function onShipmentStep(array $e): void
    {
        if ($e['step'] === ShipmentStep::ORDER_CONFIRMED->value) {
            return;
        }
        $s = Shipment::with('order')->findOrFail($e['shipmentId']);
        $o = $s->order;
        $data = [
            'orderNumber' => $o->number,
            'stepLabel' => Domain::STEP_LABELS[$e['step']],
            'comment' => $e['publicComment'] ?? '',
            'estimatedDate' => $s->estimated_delivery_date ? Format::date($s->estimated_delivery_date) : '',
            'deliveryCode' => $s->delivery_code,
            'trackingUrl' => $this->trackingUrl($o),
        ];
        $code = match ($e['step']) {
            ShipmentStep::OUT_FOR_DELIVERY->value, ShipmentStep::READY_FOR_PICKUP->value => T::OUT_FOR_DELIVERY->value,
            ShipmentStep::DELIVERED->value => T::ORDER_DELIVERED->value,
            default => T::SHIPMENT_STEP->value,
        };
        $this->notifications->notifyCustomer($code, $this->to($o), $data, ['orderId' => $o->id]);
        $this->realtime->emitToAdmins('order.updated', ['id' => $o->id]);
    }

    private function onShipmentDelayed(array $e): void
    {
        $s = Shipment::with('order')->findOrFail($e['shipmentId']);
        $this->notifications->notifyCustomer(T::SHIPMENT_DELAYED->value, $this->to($s->order), [
            'orderNumber' => $s->order->number,
            'comment' => $s->delay_reason ?? '',
            'estimatedDate' => $s->estimated_delivery_date ? Format::date($s->estimated_delivery_date) : '',
            'trackingUrl' => $this->trackingUrl($s->order),
        ], ['orderId' => $s->order_id]);
    }

    private function onStockLow(array $e): void
    {
        $v = ProductVariant::with(['product' => fn ($q) => $q->withTrashed()])->findOrFail($e['variantId']);
        $available = $v->stockAvailable();
        $this->notifications->notifyAdmins(A::LOW_STOCK->value,
            ($available <= 0 ? 'Rupture' : 'Stock faible')." : {$v->product->name} ({$v->sku}) – ".max(0, $available).' disponible(s)',
            '/stocks?q='.rawurlencode($v->sku));
        $this->realtime->emitToAdmins('stock.low', ['variantId' => $v->id, 'sku' => $v->sku, 'available' => $available]);
    }

    private function onStockBack(array $e): void
    {
        $v = ProductVariant::with(['product' => fn ($q) => $q->withTrashed()])->findOrFail($e['variantId']);
        if (Availability::variant($v->product, $v) === AvailabilityMode::UNAVAILABLE->value) {
            return;
        }
        $productUrl = config('amoria.web_public_url')."/produit/{$v->product->slug}";
        $productName = $v->product->name.($v->name !== 'Standard' ? " – {$v->name}" : '');
        foreach (BackInStockAlert::where('variant_id', $v->id)->whereNull('notified_at')->limit(500)->get() as $a) {
            $this->notifications->notifyCustomer(T::BACK_IN_STOCK->value, ['email' => $a->email, 'phone' => $a->phone],
                ['productName' => $productName, 'productUrl' => $productUrl],
                ['forceChannels' => array_values(array_filter([$a->email ? NotificationChannelType::EMAIL->value : null, $a->phone ? NotificationChannelType::SMS->value : null]))]);
            $a->update(['notified_at' => now()]);
        }
    }

    private function onArrivalMissing(array $e): void
    {
        $this->notifications->notifyAdmins(A::MISSING_ITEMS->value, "Arrivage {$e['reference']} : {$e['missing']} article(s) manquant(s)", "/arrivages/{$e['arrivalId']}");
    }

    private function onCustomerMessage(array $e): void
    {
        $c = Conversation::findOrFail($e['conversationId']);
        $this->notifications->notifyAdmins(A::NEW_MESSAGE->value, "Nouveau message de {$c->visitor_name}", "/messagerie/{$c->id}", $c->assigned_to_id);
    }

    private function onAdminReply(array $e): void
    {
        $c = Conversation::findOrFail($e['conversationId']);
        $m = Message::findOrFail($e['messageId']);
        $this->notifications->notifyCustomer(T::CONVERSATION_REPLY->value,
            ['name' => $c->visitor_name, 'email' => $c->visitor_email, 'phone' => $c->visitor_phone],
            ['message' => mb_substr($m->body, 0, 300), 'conversationUrl' => config('amoria.web_public_url')."/?chat={$c->public_token}"],
            ['orderId' => $c->order_id]);
    }

    private function onPasswordReset(array $e): void
    {
        $this->notifications->notifyCustomer(T::PASSWORD_RESET->value,
            ['name' => $e['name'], 'email' => $e['email'], 'phone' => $e['email'] ? null : $e['phone']],
            ['resetUrl' => $e['url']],
            ['forceChannels' => [$e['email'] ? NotificationChannelType::EMAIL->value : NotificationChannelType::SMS->value]]);
    }

    private function onPhoneVerification(array $e): void
    {
        $this->notifications->notifyCustomer(T::PHONE_VERIFICATION->value, ['phone' => $e['phone']], ['code' => $e['code']],
            ['forceChannels' => [NotificationChannelType::SMS->value]]);
    }
}
