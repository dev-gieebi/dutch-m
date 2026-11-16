<?php

namespace App\Services\Orders;

use App\Enums\CouponType;
use App\Enums\DeliveryGrouping;
use App\Enums\DeliveryMode;
use App\Enums\ItemType;
use App\Support\Format;

/**
 * Calcul des montants (règles 5.4, 6.4, 6.8).
 *
 * Ligne de tarification : ['variantId', 'productId', 'categoryId', 'unitPrice', 'quantity', 'itemType', 'depositPercent', 'leadTimeDays']
 */
final class Pricing
{
    public static function lineTotal(array $l): int
    {
        return $l['unitPrice'] * $l['quantity'];
    }

    public static function lineDeposit(array $l): int
    {
        return $l['itemType'] === ItemType::PREORDER->value
            ? (int) round(self::lineTotal($l) * min(100, max(0, $l['depositPercent'])) / 100)
            : 0;
    }

    /**
     * Règle 6.8 : remise d'un code promo sur les lignes éligibles.
     *
     * @param  array{type: string, value: int, minSubtotal: int, maxDiscount: ?int, categoryIds: ?array, productIds: ?array}  $c
     * @return array{discount: int, freeShipping: bool, error: ?string}
     */
    public static function evaluateCoupon(array $c, array $lines): array
    {
        $subtotal = array_sum(array_map([self::class, 'lineTotal'], $lines));
        if ($subtotal < $c['minSubtotal']) {
            return ['discount' => 0, 'freeShipping' => false, 'error' => 'Montant minimum de '.Format::fcfa($c['minSubtotal']).' requis pour ce code'];
        }
        $targeted = ! empty($c['categoryIds']) || ! empty($c['productIds']);
        $eligible = $targeted
            ? array_filter($lines, fn ($l) => in_array($l['productId'], $c['productIds'] ?? [])
                || ($l['categoryId'] !== null && in_array($l['categoryId'], $c['categoryIds'] ?? [])))
            : $lines;
        if (! $eligible) {
            return ['discount' => 0, 'freeShipping' => false, 'error' => "Ce code ne s'applique à aucun article de votre panier"];
        }
        $base = array_sum(array_map([self::class, 'lineTotal'], $eligible));
        $discount = 0;
        $freeShipping = false;
        switch ($c['type']) {
            case CouponType::PERCENT->value:
                $discount = (int) round($base * min(100, $c['value']) / 100);
                break;
            case CouponType::FIXED->value:
                $discount = min($c['value'], $base);
                break;
            case CouponType::FREE_SHIPPING->value:
                $freeShipping = true;
                break;
        }
        if ($c['maxDiscount'] !== null) {
            $discount = min($discount, $c['maxDiscount']);
        }

        return ['discount' => max(0, $discount), 'freeShipping' => $freeShipping, 'error' => null];
    }

    /**
     * Sous-total, remise, frais de livraison, total, acompte et montant dû maintenant.
     *
     * @param  array{lines: array, discount: int, couponFreeShipping: bool, zoneFee: ?int, deliveryMode: string, grouping: string, freeShippingThreshold: ?int}  $in
     */
    public static function computeTotals(array $in): array
    {
        $lines = $in['lines'];
        $local = array_filter($lines, fn ($l) => $l['itemType'] === ItemType::LOCAL->value);
        $pre = array_filter($lines, fn ($l) => $l['itemType'] === ItemType::PREORDER->value);
        $localSubtotal = array_sum(array_map([self::class, 'lineTotal'], $local));
        $preorderSubtotal = array_sum(array_map([self::class, 'lineTotal'], $pre));
        $subtotal = $localSubtotal + $preorderSubtotal;
        $discountTotal = min(max(0, $in['discount']), $subtotal);

        $shipmentsCount = ! $lines ? 0 : ($local && $pre && $in['grouping'] === DeliveryGrouping::SPLIT->value ? 2 : 1);
        $thresholdReached = $in['freeShippingThreshold'] !== null && $subtotal - $discountTotal >= $in['freeShippingThreshold'];
        $freeShipping = $in['deliveryMode'] === DeliveryMode::PICKUP->value || $in['couponFreeShipping'] || $thresholdReached;
        $feePerShipment = $freeShipping ? 0 : (int) ($in['zoneFee'] ?? 0);
        $shippingTotal = $feePerShipment * $shipmentsCount;

        $total = $subtotal - $discountTotal + $shippingTotal;
        $lineDeposits = array_map([self::class, 'lineDeposit'], array_values($lines));
        $depositAmount = array_sum($lineDeposits);
        $deferred = $preorderSubtotal - $depositAmount;
        $dueNow = max(0, $total - $deferred);

        return [
            'subtotal' => $subtotal,
            'localSubtotal' => $localSubtotal,
            'preorderSubtotal' => $preorderSubtotal,
            'discountTotal' => $discountTotal,
            'shippingTotal' => $shippingTotal,
            'shipmentsCount' => $shipmentsCount,
            'total' => $total,
            'depositAmount' => $depositAmount,
            'dueNow' => $dueNow,
            'balanceAfter' => $total - $dueNow,
            'freeShipping' => $freeShipping && $in['deliveryMode'] === DeliveryMode::HOME->value,
            'lineDeposits' => $lineDeposits,
            'feePerShipment' => $feePerShipment,
        ];
    }
}
