<?php

namespace App\Services\Payments\Providers;

use App\Enums\PaymentMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Sélectionne l'opérateur selon le moyen de paiement et le mode (mock / live). */
class PaymentProviderFactory
{
    public function __construct(
        public readonly AirtelMoneyProvider $airtel,
        public readonly MoovMoneyProvider $moov,
        public readonly MockPaymentProvider $mock,
    ) {}

    public function isMock(): bool
    {
        return config('amoria.payments.mode') === 'mock';
    }

    public function forMethod(string $method): PaymentProvider
    {
        if ($method === PaymentMethod::CASH_ON_DELIVERY->value) {
            throw new HttpException(400, 'Aucun opérateur pour le paiement à la livraison');
        }
        if ($this->isMock()) {
            return $this->mock;
        }

        return $method === PaymentMethod::AIRTEL_MONEY->value ? $this->airtel : $this->moov;
    }

    public function byCode(string $code): PaymentProvider
    {
        return match ($code) {
            'airtel' => $this->airtel,
            'moov' => $this->moov,
            'mock' => $this->mock,
            default => throw new HttpException(404, 'Opérateur inconnu'),
        };
    }
}
