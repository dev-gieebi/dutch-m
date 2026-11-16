<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentRecordStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Format;

/** Applique les paiements réussis à la commande (montants, statut, confirmation). À appeler dans une transaction. */
class PaymentLedgerService
{
    public function __construct(private readonly OrderWorkflowService $workflow) {}

    public function lockOrder(int $orderId): Order
    {
        return Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
    }

    /** @return array{order: Order, duplicate: bool, confirmed: bool} */
    public function applySucceeded(Payment $payment): array
    {
        $order = $this->lockOrder($payment->order_id);
        $alreadySettled = $order->amount_paid >= $order->total && $order->total > 0;
        if (in_array($order->status, [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value], true) || $alreadySettled) {
            Payment::whereKey($payment->id)->update(['is_duplicate' => true]);
            $payment->is_duplicate = true;

            return ['order' => $order, 'duplicate' => true, 'confirmed' => false];
        }
        $order->amount_paid += $payment->amount;
        $order->balance_due = max(0, $order->total - $order->amount_paid);
        $order->payment_status = OrderState::computePaymentStatus($order->total, $order->amount_paid, $order->amount_refunded, $order->payment_status, $order->payment_method);
        Order::whereKey($order->id)->update(['amount_paid' => $order->amount_paid, 'balance_due' => $order->balance_due, 'payment_status' => $order->payment_status]);
        $confirmed = false;
        if ($order->status === OrderStatus::PENDING_PAYMENT->value) {
            $this->workflow->confirm($order, $payment->paid_at ?? now());
            $confirmed = true;
        }

        return ['order' => $order, 'duplicate' => false, 'confirmed' => $confirmed];
    }

    /**
     * Enregistre un encaissement (espèces, mobile money à la remise, paiement manuel).
     *
     * @param  array{amount: int, method: string, provider: string, reference?: ?string, adminUserId: ?int, isManual?: bool}  $input
     * @return array{payment: Payment, result: array}
     */
    public function recordOffline(Order $order, array $input): array
    {
        $kind = $order->amount_paid === 0 && $input['amount'] >= $order->total ? PaymentKind::FULL
            : ($order->amount_paid === 0 ? PaymentKind::DEPOSIT : PaymentKind::BALANCE);
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => $input['provider'],
            'method' => $input['method'],
            'amount' => $input['amount'],
            'currency' => 'XAF',
            'kind' => $kind->value,
            'status' => PaymentRecordStatus::SUCCEEDED->value,
            'provider_reference' => strtoupper($input['provider'])."-{$order->id}-".Format::randomToken(6),
            'provider_transaction_id' => $input['reference'] ?? null,
            'paid_at' => now(),
            'is_manual' => $input['isManual'] ?? false,
            'manual_reference' => $input['reference'] ?? null,
            'admin_user_id' => $input['adminUserId'],
        ])->refresh();

        return ['payment' => $payment, 'result' => $this->applySucceeded($payment)];
    }
}
