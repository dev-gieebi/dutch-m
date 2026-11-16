<?php

namespace App\Services\Catalog;

use App\Enums\AvailabilityMode;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Format;
use Carbon\CarbonInterface;

/** Règles de prix et de disponibilité (règle 6.1). */
final class Availability
{
    public static function isPromoActive(ProductVariant $v, ?CarbonInterface $now = null): bool
    {
        return $v->promo_price !== null && $v->promo_price < $v->price && Format::isWithin($v->promo_starts_at, $v->promo_ends_at, $now);
    }

    /** Prix effectif et prix barré affiché. @return array{price: int, compareAtPrice: int|null} */
    public static function effectivePrice(ProductVariant $v, ?CarbonInterface $now = null): array
    {
        if (self::isPromoActive($v, $now)) {
            return ['price' => (int) $v->promo_price, 'compareAtPrice' => (int) $v->price];
        }

        return ['price' => (int) $v->price, 'compareAtPrice' => $v->compare_at_price && $v->compare_at_price > $v->price ? (int) $v->compare_at_price : null];
    }

    public static function stockAvailable(ProductVariant $v): int
    {
        return (int) $v->stock_on_hand - (int) $v->stock_reserved;
    }

    /** Disponibilité effective d'une variante. */
    public static function variant(Product $p, ProductVariant $v): string
    {
        if (! $v->is_active) {
            return AvailabilityMode::UNAVAILABLE->value;
        }
        $mode = $v->availability_mode ?? $p->availability_mode;
        if ($mode === AvailabilityMode::IN_STOCK->value && self::stockAvailable($v) <= 0) {
            return $p->auto_preorder_when_out_of_stock ? AvailabilityMode::PREORDER->value : AvailabilityMode::UNAVAILABLE->value;
        }

        return $mode;
    }

    /** @param  iterable<ProductVariant>  $variants */
    public static function product(Product $p, iterable $variants): string
    {
        $modes = [];
        foreach ($variants as $v) {
            $modes[] = self::variant($p, $v);
        }
        if (in_array(AvailabilityMode::IN_STOCK->value, $modes, true)) {
            return AvailabilityMode::IN_STOCK->value;
        }
        if (in_array(AvailabilityMode::PREORDER->value, $modes, true)) {
            return AvailabilityMode::PREORDER->value;
        }

        return AvailabilityMode::UNAVAILABLE->value;
    }

    /** Quantité maximale commandable. */
    public static function maxOrderQuantity(Product $p, ProductVariant $v, int $globalMaxPreorder): int
    {
        $a = self::variant($p, $v);
        if ($a === AvailabilityMode::IN_STOCK->value) {
            return max(0, self::stockAvailable($v));
        }
        if ($a === AvailabilityMode::PREORDER->value) {
            return $p->max_preorder_qty ?? $globalMaxPreorder;
        }

        return 0;
    }

    /** Pourcentage d'acompte applicable à un produit en précommande. */
    public static function depositPercentFor(Product $p, int $globalPercent): int
    {
        if ($p->full_payment_required) {
            return 100;
        }

        return $p->deposit_percent ?? $globalPercent;
    }
}
