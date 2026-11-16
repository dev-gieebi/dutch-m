<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement métier (commande créée, paiement reçu, étape de suivi…).
 * Les noms sont listés dans les constantes ci-dessous ; écouté par NotificationsListener.
 */
class DomainEvent
{
    use Dispatchable;

    public const ORDER_CREATED = 'order.created';
    public const ORDER_CANCELLED = 'order.cancelled';
    public const PAYMENT_SUCCEEDED = 'payment.succeeded';
    public const PAYMENT_FAILED = 'payment.failed';
    public const PAYMENT_DUPLICATE = 'payment.duplicate';
    public const SHIPMENT_STEP = 'shipment.step';
    public const SHIPMENT_DELAYED = 'shipment.delayed';
    public const STOCK_LOW = 'stock.low';
    public const STOCK_BACK = 'stock.back';
    public const ARRIVAL_MISSING = 'arrival.missing';
    public const CONVERSATION_CUSTOMER_MESSAGE = 'conversation.customer-message';
    public const CONVERSATION_ADMIN_REPLY = 'conversation.admin-reply';
    public const AUTH_PASSWORD_RESET = 'auth.password-reset';
    public const AUTH_PHONE_VERIFICATION = 'auth.phone-verification';

    public function __construct(
        public readonly string $name,
        public readonly array $payload = [],
    ) {}

    public static function emit(string $name, array $payload = []): void
    {
        event(new self($name, $payload));
    }
}
