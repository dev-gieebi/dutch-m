<?php

namespace App\Services\Payments\Providers;

use App\Enums\PaymentRecordStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Moov Money / MobiCash Gabon via passerelle marchande.
 * Les chemins (/v1/payments, /v1/refunds) et en-têtes suivent le contrat marchand courant ;
 * adaptez MOOV_BASE_URL et, si besoin, les champs ci-dessous à votre contrat opérateur.
 * Signature des callbacks : HMAC-SHA256 hexadécimal du corps brut (MOOV_WEBHOOK_SECRET), en-tête `x-moov-signature`.
 */
class MoovMoneyProvider implements PaymentProvider
{
    private const STATUS_MAP = [
        'SUCCESS' => 'SUCCEEDED',
        'SUCCESSFUL' => 'SUCCEEDED',
        'COMPLETED' => 'SUCCEEDED',
        'FAILED' => 'FAILED',
        'REJECTED' => 'FAILED',
        'CANCELLED' => 'FAILED',
        'EXPIRED' => 'EXPIRED',
        'PENDING' => 'PENDING',
    ];

    public function code(): string
    {
        return 'moov';
    }

    private function cfg(string $key): string
    {
        return (string) config("amoria.payments.moov.{$key}");
    }

    private function base(): string
    {
        return rtrim($this->cfg('base_url'), '/');
    }

    private function headers(string $body = ''): array
    {
        $timestamp = (string) (int) (microtime(true) * 1000);

        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->cfg('api_key'),
            'x-merchant-id' => $this->cfg('merchant_id'),
            'x-timestamp' => $timestamp,
            'x-signature' => hash_hmac('sha256', "{$timestamp}.{$body}", $this->cfg('api_secret')),
        ];
    }

    private function send(string $method, string $path, ?array $payload = null)
    {
        $body = $payload !== null ? json_encode($payload) : '';
        $req = Http::withHeaders($this->headers($body));

        return $payload !== null
            ? $req->withBody($body, 'application/json')->send($method, "{$this->base()}{$path}")
            : $req->send($method, "{$this->base()}{$path}");
    }

    private static function status(?string $s): string
    {
        return self::STATUS_MAP[strtoupper((string) $s)] ?? PaymentRecordStatus::PENDING->value;
    }

    public function initiate(Payment $payment): array
    {
        $res = $this->send('POST', '/v1/payments', [
            'merchantId' => $this->cfg('merchant_id'),
            'reference' => $payment->provider_reference,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'msisdn' => '241'.preg_replace('/^\+?241/', '', (string) $payment->payer_phone),
            'description' => "Commande DutCh’M {$payment->provider_reference}",
            'callbackUrl' => rtrim(config('app.url'), '/').'/api/v1/payments/webhooks/moov',
        ]);
        $data = $res->json() ?? [];
        if (! $res->ok()) {
            Log::warning('Initiation Moov refusée : '.json_encode($data));

            return ['providerReference' => $payment->provider_reference, 'status' => PaymentRecordStatus::FAILED->value, 'instructions' => $data['message'] ?? null];
        }

        return [
            'providerReference' => $payment->provider_reference,
            'status' => PaymentRecordStatus::PENDING->value,
            'providerTransactionId' => $data['transactionId'] ?? null,
            'instructions' => 'Validez le paiement sur votre téléphone (menu Moov Money / MobiCash) avec votre code secret.',
        ];
    }

    public function checkStatus(string $providerReference): array
    {
        $data = $this->send('GET', '/v1/payments/'.rawurlencode($providerReference))->json() ?? [];
        $status = self::status($data['status'] ?? null);

        return ['status' => $status, 'providerTransactionId' => $data['transactionId'] ?? null, 'failureReason' => $status === 'FAILED' ? ($data['message'] ?? null) : null];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $secret = $this->cfg('webhook_secret');
        $signature = $headers['x-moov-signature'] ?? '';

        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function parseWebhook(string $rawBody): array
    {
        $b = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        $status = self::status($b['status'] ?? null);

        return [
            'eventId' => 'moov:'.($b['eventId'] ?? "{$b['reference']}:{$b['status']}"),
            'providerReference' => $b['reference'],
            'status' => $status,
            'amount' => isset($b['amount']) ? (int) $b['amount'] : null,
            'currency' => $b['currency'] ?? null,
            'providerTransactionId' => $b['transactionId'] ?? null,
            'failureReason' => $status === 'FAILED' ? ($b['message'] ?? null) : null,
        ];
    }

    public function refund(Payment $payment, int $amount): array
    {
        $res = $this->send('POST', '/v1/refunds', ['reference' => $payment->provider_reference, 'transactionId' => $payment->provider_transaction_id, 'amount' => $amount]);
        $data = $res->json() ?? [];

        return ['success' => $res->ok(), 'providerReference' => $data['refundId'] ?? null, 'message' => $data['message'] ?? null];
    }
}
