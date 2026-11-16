<?php

namespace App\Services\Catalog;

use App\Enums\AvailabilityMode;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Transforme les modèles du catalogue en DTO publics (ProductCardDto, ProductDetailDto…). */
final class CatalogMapper
{
    public static function image(ProductImage $i): array
    {
        return ['id' => $i->id, 'url' => $i->url, 'mediumUrl' => $i->medium_url, 'thumbUrl' => $i->thumb_url, 'alt' => $i->alt, 'isMain' => $i->is_main, 'variantId' => $i->variant_id];
    }

    /** @param  iterable<ProductImage>  $images */
    public static function sortImages(iterable $images): Collection
    {
        return collect($images)->sort(fn ($a, $b) => ((int) $b->is_main <=> (int) $a->is_main) ?: ($a->sort_order <=> $b->sort_order) ?: ($a->id <=> $b->id))->values();
    }

    private static function cheapest(Collection $variants, CarbonInterface $now): ?ProductVariant
    {
        $active = $variants->filter(fn ($v) => $v->is_active)->values();
        if ($active->isEmpty()) {
            return null;
        }

        return $active->reduce(fn ($best, $v) => $best === null || Availability::effectivePrice($v, $now)['price'] < Availability::effectivePrice($best, $now)['price'] ? $v : $best);
    }

    /** @param  Collection<int, ProductVariant>|null  $variants */
    public static function card(Product $p, iterable $images, ?Collection $variants = null, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $variants ??= $p->variants ?? collect();
        $cheapest = self::cheapest($variants, $now);
        $price = $cheapest ? Availability::effectivePrice($cheapest, $now) : ['price' => $p->base_price, 'compareAtPrice' => $p->compare_at_price];
        $active = $variants->filter(fn ($v) => $v->is_active)->values();
        $def = $active->first(fn ($v) => $v->is_default) ?? $active->first();
        $main = self::sortImages($images)->first();

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'reference' => $p->reference,
            'price' => $price['price'],
            'compareAtPrice' => $price['compareAtPrice'],
            'availability' => $variants->isNotEmpty() ? Availability::product($p, $active) : AvailabilityMode::UNAVAILABLE->value,
            'leadTimeDays' => $p->lead_time_days,
            'image' => $main ? self::image($main) : null,
            'isNew' => $p->is_new,
            'isBestSeller' => $p->is_best_seller,
            'onSale' => $price['compareAtPrice'] !== null,
            'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name, 'slug' => $p->category->slug] : null,
            'defaultVariantId' => $active->count() === 1 ? $def?->id : null,
        ];
    }

    public static function variant(Product $p, ProductVariant $v, Collection $images, array $settings, ?CarbonInterface $now = null): array
    {
        $availability = Availability::variant($p, $v);
        $price = Availability::effectivePrice($v, $now);

        return [
            'id' => $v->id,
            'sku' => $v->sku,
            'name' => $v->name,
            'price' => $price['price'],
            'compareAtPrice' => $price['compareAtPrice'],
            'availability' => $availability,
            'stockAvailable' => $availability === AvailabilityMode::IN_STOCK->value ? Availability::stockAvailable($v) : null,
            'maxQuantity' => $availability === AvailabilityMode::UNAVAILABLE->value ? 0 : Availability::maxOrderQuantity($p, $v, $settings['maxPreorderQty']),
            'leadTimeDays' => $p->lead_time_days,
            'attributes' => $v->attributeValues->map(fn ($av) => [
                'attribute' => $av->attribute?->name ?? '',
                'type' => $av->attribute?->type ?? 'OTHER',
                'value' => $av->value,
                'hexColor' => $av->hex_color,
            ])->values()->all(),
            'imageIds' => $images->filter(fn ($i) => $i->variant_id === $v->id)->pluck('id')->values()->all(),
            'isDefault' => $v->is_default,
        ];
    }

    /** @param  iterable<\App\Models\Category>  $breadcrumbs */
    public static function detail(Product $p, array $settings, iterable $breadcrumbs, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $images = self::sortImages($p->images);
        $variants = $p->variants->filter(fn ($v) => $v->is_active)->sort(fn ($a, $b) => ($a->sort_order <=> $b->sort_order) ?: ($a->id <=> $b->id))->values();
        $colors = $p->colors ?? [];
        foreach ($variants as $v) {
            foreach ($v->attributeValues as $a) {
                if ($a->attribute?->type === 'COLOR') {
                    $colors[] = $a->value;
                }
            }
        }

        return self::card($p, $images, $variants, $now) + [
            'shortDescription' => $p->short_description,
            'description' => $p->description,
            'dimensions' => $p->dimensions,
            'material' => $p->material,
            'weight' => $p->weight,
            'careInstructions' => $p->care_instructions,
            'colors' => array_values(array_unique($colors)),
            'images' => $images->map(fn ($i) => self::image($i))->all(),
            'variants' => $variants->map(fn ($v) => self::variant($p, $v, $images, $settings, $now))->all(),
            'seoTitle' => $p->seo_title,
            'seoDescription' => $p->seo_description,
            'depositPercent' => Availability::depositPercentFor($p, $settings['depositPercent']),
            'fullPaymentRequired' => $p->full_payment_required,
            'breadcrumbs' => collect($breadcrumbs)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])->values()->all(),
        ];
    }
}
