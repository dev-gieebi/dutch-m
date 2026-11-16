<?php

namespace App\Services\Catalog;

use App\Models\Product;

/** Recalcule les champs dénormalisés des produits (prix courant, promo, disponibilité). */
class ProductIndexer
{
    /** @param  list<int>|null  $productIds  null = tous les produits */
    public function refresh(?array $productIds = null): void
    {
        if ($productIds !== null && ! $productIds) {
            return;
        }
        $now = now();
        Product::with('variants')
            ->when($productIds !== null, fn ($q) => $q->whereIn('id', array_values(array_unique($productIds))))
            ->chunkById(200, function ($rows) use ($now) {
                foreach ($rows as $p) {
                    $active = $p->variants->filter(fn ($v) => $v->is_active);
                    $prices = $active->map(fn ($v) => Availability::effectivePrice($v, $now));
                    $currentPrice = $prices->isNotEmpty() ? $prices->min('price') : $p->base_price;
                    $onSale = $active->contains(fn ($v) => Availability::isPromoActive($v, $now)) || $prices->contains(fn ($x) => $x['compareAtPrice'] !== null);
                    $computed = Availability::product($p, $active);
                    if ($p->current_price !== $currentPrice || $p->on_sale !== $onSale || $p->computed_availability !== $computed) {
                        Product::whereKey($p->id)->update(['current_price' => $currentPrice, 'on_sale' => $onSale, 'computed_availability' => $computed]);
                    }
                }
            });
    }
}
