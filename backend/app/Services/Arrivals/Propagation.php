<?php

namespace App\Services\Arrivals;

use App\Enums\ShipmentStep;
use App\Support\Domain;

/** Propagation de l'état des arrivages aux expéditions précommande (règle 6.6). */
final class Propagation
{
    /**
     * Étape cible d'une expédition précommande : elle avance au rythme de la ligne la moins avancée.
     * Une ligne non rattachée compte comme « Commande confirmée » ; une ligne partiellement reçue reste « Arrivé au Gabon ».
     *
     * @param  list<array{arrivalStep: ?string, quantity: int, receivedQty: int}>  $items
     */
    public static function computeShipmentTarget(array $items): string
    {
        if (! $items) {
            return ShipmentStep::ORDER_CONFIRMED->value;
        }
        $indexes = array_map(function ($i) {
            $step = $i['arrivalStep'] ?? ShipmentStep::ORDER_CONFIRMED->value;
            if ($step === ShipmentStep::RECEIVED_IN_STOCK->value && $i['receivedQty'] < $i['quantity']) {
                $step = ShipmentStep::ARRIVED_IN_GABON->value;
            }

            return array_search($step, Domain::PREORDER_STEPS, true);
        }, $items);

        return Domain::PREORDER_STEPS[max(0, min(array_map(fn ($x) => $x === false ? -1 : $x, $indexes)))];
    }

    /** Étapes à enregistrer (dans l'ordre) pour amener une expédition de $current à $target. @return list<string> */
    public static function stepsBetween(?string $current, string $target): array
    {
        $from = $current ? array_search($current, Domain::PREORDER_STEPS, true) : -1;
        if ($from === false) {
            return [];
        }
        $to = array_search($target, Domain::PREORDER_STEPS, true);

        return $to > $from ? array_slice(Domain::PREORDER_STEPS, $from + 1, $to - $from) : [];
    }
}
