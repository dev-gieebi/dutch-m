<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStep as S;
use App\Support\Domain;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Règles d'enchaînement des étapes et statuts dérivés. */
final class OrderState
{
    private const TERMINAL = ['CANCELLED', 'RETURNED'];

    /** Règle 6.5 : une étape ne peut être enregistrée que dans l'ordre (sauf forçage justifié par un super-admin). */
    public static function assertNextStep(array $sequence, ?string $current, string $next, bool $force = false): void
    {
        $bad = fn (string $m) => new HttpException(400, $m);
        if (in_array($current, self::TERMINAL, true)) {
            throw $bad('Cette expédition est clôturée (annulée ou retournée)');
        }
        if ($next === S::DELAYED->value) {
            throw $bad('Utilisez « Signaler un retard » pour cette étape');
        }
        if ($next === S::CANCELLED->value) {
            if ($current === S::DELIVERED->value) {
                throw $bad('Une expédition livrée ne peut pas être annulée');
            }

            return;
        }
        if ($next === S::RETURNED->value) {
            if ($current !== S::DELIVERED->value && $current !== S::OUT_FOR_DELIVERY->value) {
                throw $bad("Un retour n'est possible qu'après une livraison ou une tentative de livraison");
            }

            return;
        }
        $currentIndex = $current ? array_search($current, $sequence, true) : -1;
        $currentIndex = $currentIndex === false ? -1 : $currentIndex;
        $nextIndex = array_search($next, $sequence, true);
        $label = Domain::STEP_LABELS[$next];
        if ($nextIndex === false) {
            throw $bad("L'étape « {$label} » ne s'applique pas à cette expédition");
        }
        if ($nextIndex <= $currentIndex) {
            throw $bad("L'étape « {$label} » a déjà été enregistrée");
        }
        if ($nextIndex !== $currentIndex + 1 && ! $force) {
            $expected = Domain::STEP_LABELS[$sequence[$currentIndex + 1]];
            throw $bad("Les étapes doivent être enregistrées dans l'ordre : prochaine étape attendue « {$expected} »");
        }
    }

    public static function nextStepOf(array $sequence, ?string $current): ?string
    {
        if (in_array($current, self::TERMINAL, true) || $current === S::DELIVERED->value) {
            return null;
        }
        $i = $current ? array_search($current, $sequence, true) : -1;

        return $sequence[($i === false ? -1 : $i) + 1] ?? null;
    }

    /** Statut de paiement à partir des montants encaissés et remboursés. */
    public static function computePaymentStatus(int $total, int $amountPaid, int $amountRefunded, string $paymentStatus, string $paymentMethod): string
    {
        if ($amountRefunded > 0) {
            return $amountRefunded >= $amountPaid ? PaymentStatus::REFUNDED->value : PaymentStatus::PARTIALLY_REFUNDED->value;
        }
        if ($amountPaid >= $total && $total > 0) {
            return PaymentStatus::PAID->value;
        }
        if ($amountPaid > 0) {
            return $paymentMethod === PaymentMethod::CASH_ON_DELIVERY->value ? PaymentStatus::AWAITING_CASH->value : PaymentStatus::DEPOSIT_PAID->value;
        }
        if ($total === 0) {
            return PaymentStatus::PAID->value;
        }

        return $paymentStatus;
    }

    /** Statut global de la commande à partir de ses expéditions. @param list<string|null> $steps */
    public static function computeOrderStatus(string $current, array $steps): string
    {
        if (in_array($current, [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value, OrderStatus::PENDING_PAYMENT->value], true)) {
            return $current;
        }
        $active = array_values(array_filter($steps, fn ($s) => $s !== S::CANCELLED->value));
        if (! $active) {
            return OrderStatus::CANCELLED->value;
        }
        $delivered = count(array_filter($active, fn ($s) => $s === S::DELIVERED->value || $s === S::RETURNED->value));
        if ($delivered === count($active)) {
            return OrderStatus::DELIVERED->value;
        }
        if ($delivered > 0) {
            return OrderStatus::PARTIALLY_DELIVERED->value;
        }
        foreach ($active as $s) {
            if ($s && $s !== S::ORDER_CONFIRMED->value) {
                return OrderStatus::IN_PROGRESS->value;
            }
        }

        return OrderStatus::CONFIRMED->value;
    }
}
