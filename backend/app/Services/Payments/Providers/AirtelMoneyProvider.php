<?php

namespace App\Services\Payments\Providers;

use App\Enums\PaymentRecordStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Airtel Money Gabon (API Airtel Africa « Collection »).
 * Points d'accès configurables par variables d'environnement (AIRTEL_*).
 * Signature des callbacks : HMAC-SHA256 du corps brut avec AIRTEL_WEBHOOK_SECRET, en-tête `x-signature`.
 */
class AirtelMoneyProvider implements PaymentProvider
{
    private const STATUS_MAP = [
        'TS' => 'SUCCEEDED',
        'TF' => 'FAILED',
        'TE' => 'EXPIRED',
        'TIP' => 'PENDING',
        'TA' => 'PENDING',
    ];

    public function code(): string
    {
        return 'airtel';
    }

    private function cfg(string $key): string
    {
        return (string) config("amoria.payments.airtel.{$key}");
    }

    private function base(): string
    {
        return rtrim($this->cfg('base_url'), '/');
    }

    private function accessToken(): string
    {
        return Cache::remember('airtel-access-token', 3000, function () {
            $res = Http::acceptJson()->post("{$this->base()}/auth/oauth2/token", [
                'client_id' => $this->cfg('client_id'),
                'client_secret' => $this->cfg('client_secret'),
                'grant_type' => 'client_credentials',
            ]);
            if (! $res->ok()) {
                throw new HttpException(503, 'Airtel Money est momentanément indisponible');
            }

            return (string) $res->json('access_token');
        });
    }

    private function client()
    {
        return Http::acceptJson()->withHeaders([
            'X-Country' => $this->cfg('country'),
            'X-Currency' => $this->cfg('currency'),
        ])->withToken($this->accessToken());
    }

    private static function localMsisdn(?string $phone): string
    {
        return preg_replace('/^\+?241/', '', (string) $phone);
    }

    public function initiate(Payment $payment): array
    {
        $res = $this->client()->post("{$this->base()}/merchant/v1/payments/", [
            'reference' => mb_substr("DUTCHM {$payment->provider_reference}", 0, 64),
            'subscriber' => ['country' => $this->cfg('country'), 'currency' => $payment->currency, 'msisdn' => self::localMsisdn($payment->payer_phone)],
            'transaction' => ['amount' => $payment->amount, 'country' => $this->cfg('country'), 'currency' => $payment->currency, 'id' => $payment->provider_reference],
        ]);
        $body = $res->json() ?? [];
        if (! $res->ok() || ($body['status']['success'] ?? null) === false) {
            Log::warning('Initiation Airtel refusée : '.json_encode($body));

            return ['providerReference' => $payment->provider_reference, 'status' => PaymentRecordStatus::FAILED->value, 'instructions' => $body['status']['message'] ?? null];
        }

        return [
            'providerReference' => $payment->provider_reference,
            'status' => PaymentRecordStatus::PENDING->value,
            'instructions' => 'Validez le paiement sur votre téléphone en saisissant votre code secret Airtel Money.',
        ];
    }

    public function checkStatus(string $providerReference): array
    {
        $tx = $this->client()->get("{$this->base()}/standard/v1/payments/".rawurlencode($providerReference))->json('data.transaction') ?? [];

        return [
            'status' => self::STATUS_MAP[$tx['status'] ?? ''] ?? PaymentRecordStatus::PENDING->value,
            'providerTransactionId' => $tx['airtel_money_id'] ?? null,
            'failureReason' => ($tx['status'] ?? null) === 'TF' ? ($tx['message'] ?? null) : null,
        ];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $secret = $this->cfg('webhook_secret');
        $signature = $headers['x-signature'] ?? '';
        if ($secret === '' || $signature === '') {
            return false;
        }
        $hex = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($hex, $signature) || hash_equals(base64_encode(hex2bin($hex)), $signature);
    }

    public function parseWebhook(string $rawBody): array
    {
        $tx = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR)['transaction'];

        return [
            'eventId' => "airtel:{$tx['id']}:{$tx['status_code']}",
            'providerReference' => $tx['id'],
            'status' => self::STATUS_MAP[$tx['status_code']] ?? PaymentRecordStatus::PENDING->value,
            'amount' => isset($tx['amount']) ? (int) $tx['amount'] : null,
            'currency' => $tx['currency'] ?? null,
            'providerTransactionId' => $tx['airtel_money_id'] ?? null,
            'failureReason' => $tx['status_code'] === 'TF' ? ($tx['message'] ?? null) : null,
        ];
    }

    public function refund(Payment $payment, int $amount): array
    {
        $res = $this->client()->post("{$this->base()}/standard/v1/payments/refund", ['transaction' => ['airtel_money_id' => $payment->provider_transaction_id]]);
        $body = $res->json() ?? [];

        return [
            'success' => $res->ok() && ($body['status']['success'] ?? null) !== false,
            'providerReference' => $body['data']['transaction']['airtel_money_id'] ?? null,
            'message' => $body['status']['message'] ?? null,
        ];
    }
}
