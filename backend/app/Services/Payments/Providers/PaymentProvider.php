<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;

/**
 * Contrat commun à tous les opérateurs de paiement (Airtel Money, Moov Money, fictif…).
 *
 * initiate()     → ['providerReference', 'status', 'instructions'?, 'providerTransactionId'?]
 * checkStatus()  → ['status', 'providerTransactionId'?, 'failureReason'?]
 * parseWebhook() → ['eventId', 'providerReference', 'status', 'amount'?, 'currency'?, 'providerTransactionId'?, 'failureReason'?]
 * refund()       → ['success', 'providerReference'?, 'message'?]
 */
interface PaymentProvider
{
    public function code(): string;

    public function initiate(Payment $payment): array;

    public function checkStatus(string $providerReference): array;

    /** @param  array<string, string>  $headers  en-têtes en minuscules */
    public function verifyWebhook(array $headers, string $rawBody): bool;

    public function parseWebhook(string $rawBody): array;

    public function refund(Payment $payment, int $amount): array;
}
