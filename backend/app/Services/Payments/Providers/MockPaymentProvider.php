<?php

namespace App\Services\Payments\Providers;

use App\Enums\PaymentRecordStatus;
use App\Models\Payment;
use App\Support\Format;
use Illuminate\Support\Facades\Cache;

/** Fournisseur fictif pour le développement : la page « Paiement en cours » propose de simuler succès / échec. */
class MockPaymentProvider implements PaymentProvider
{
    public function code(): string
    {
        return 'mock';
    }

    private function secret(): string
    {
        return (string) config('amoria.payments.mock_webhook_secret');
    }

    public function initiate(Payment $payment): array
    {
        return [
            'providerReference' => $payment->provider_reference,
            'status' => PaymentRecordStatus::PENDING->value,
            'instructions' => 'Mode test : aucun débit réel. Utilisez les boutons de simulation pour valider ou refuser le paiement.',
        ];
    }

    public function checkStatus(string $providerReference): array
    {
        return ['status' => Cache::get("mock-payment:{$providerReference}", PaymentRecordStatus::PENDING->value)];
    }

    /** Construit un webhook signé, comme le ferait un opérateur. @return array{headers: array<string, string>, rawBody: string} */
    public function buildWebhook(Payment $payment, string $outcome): array
    {
        $rawBody = json_encode([
            'eventId' => 'evt_'.Format::randomToken(10),
            'reference' => $payment->provider_reference,
            'status' => $outcome === 'success' ? 'SUCCEEDED' : 'FAILED',
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'transactionId' => 'MOCK-'.Format::randomToken(8),
        ]);
        Cache::put("mock-payment:{$payment->provider_reference}", $outcome === 'success' ? PaymentRecordStatus::SUCCEEDED->value : PaymentRecordStatus::FAILED->value, 86400);

        return ['headers' => ['x-mock-signature' => hash_hmac('sha256', $rawBody, $this->secret())], 'rawBody' => $rawBody];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $sig = $headers['x-mock-signature'] ?? '';

        return $sig !== '' && hash_equals(hash_hmac('sha256', $rawBody, $this->secret()), $sig);
    }

    public function parseWebhook(string $rawBody): array
    {
        $b = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        return [
            'eventId' => "mock:{$b['eventId']}",
            'providerReference' => $b['reference'],
            'status' => $b['status'] === 'SUCCEEDED' ? PaymentRecordStatus::SUCCEEDED->value : PaymentRecordStatus::FAILED->value,
            'amount' => $b['amount'] ?? null,
            'currency' => $b['currency'] ?? null,
            'providerTransactionId' => $b['transactionId'] ?? null,
            'failureReason' => $b['status'] === 'FAILED' ? 'Paiement refusé (simulation)' : null,
        ];
    }

    public function refund(Payment $payment, int $amount): array
    {
        return ['success' => true, 'providerReference' => 'MOCK-RF-'.Format::randomToken(8)];
    }
}
