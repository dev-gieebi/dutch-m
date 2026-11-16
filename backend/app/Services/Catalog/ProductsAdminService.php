<?php

namespace App\Services\Catalog;

use App\Enums\AvailabilityMode;
use App\Enums\ProductStatus;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\AuditService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockEffects;
use App\Services\NumberingService;
use App\Support\AdminPrincipal;
use App\Support\Csv;
use App\Support\Format;
use App\Support\Html;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductsAdminService
{
    private const PRODUCT_PRICE_FIELDS = ['basePrice', 'compareAtPrice', 'costPrice'];

    private const VARIANT_PRICE_FIELDS = ['price', 'compareAtPrice', 'promoPrice', 'promoStartsAt', 'promoEndsAt', 'costPrice'];

    private const PRODUCT_SCALARS = [
        'name', 'categoryId', 'shortDescription', 'dimensions', 'material', 'colors', 'careInstructions', 'weight', 'basePrice',
        'compareAtPrice', 'costPrice', 'availabilityMode', 'leadTimeDays', 'depositPercent', 'fullPaymentRequired',
        'autoPreorderWhenOutOfStock', 'maxPreorderQty', 'supplierId', 'seoTitle', 'seoDescription', 'isFeatured', 'isNew', 'isBestSeller',
    ];

    public const CSV_HEADERS = [
        'reference', 'name', 'category_code', 'status', 'availability', 'base_price', 'compare_at_price', 'cost_price',
        'lead_time_days', 'material', 'dimensions', 'short_description', 'sku', 'variant_name', 'variant_price', 'stock_on_hand', 'low_stock_threshold',
    ];

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly InventoryService $inventory,
        private readonly ProductIndexer $indexer,
        private readonly AuditService $audit,
    ) {}

    private function canEditPrices(AdminPrincipal $actor): bool
    {
        return $actor->can('prices.write');
    }

    private function assertPriceRights(AdminPrincipal $actor, ?object $current, array $dto, array $fields): void
    {
        if ($this->canEditPrices($actor)) {
            return;
        }
        $norm = function ($v) {
            if ($v instanceof \DateTimeInterface) {
                return Format::iso(Carbon::instance($v));
            }
            if (is_string($v) && strtotime($v) !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                return Format::iso($v);
            }

            return $v;
        };
        foreach ($fields as $f) {
            if (! array_key_exists($f, $dto)) {
                continue;
            }
            $prev = $current ? $current->getAttribute(Str::snake($f)) : null;
            if ($norm($prev) !== $norm($dto[$f])) {
                throw new HttpException(403, "Vous n'avez pas le droit de modifier les prix");
            }
        }
    }

    public function list(array $q): array
    {
        $qb = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_variants as v', 'v.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->groupBy('p.id', 'c.id', 'c.name')
            ->select('p.*', 'c.id as cat_id', 'c.name as cat_name')
            ->selectRaw('COALESCE(SUM(v.stock_on_hand), 0) AS agg_on_hand, COALESCE(SUM(v.stock_reserved), 0) AS agg_reserved, COUNT(v.id) AS agg_variants')
            ->selectRaw('SUM(CASE WHEN v.is_active = 1 AND (v.stock_on_hand - v.stock_reserved) <= v.low_stock_threshold THEN 1 ELSE 0 END) AS agg_low');
        if (! empty($q['q'])) {
            $like = "%{$q['q']}%";
            $qb->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('p.reference', 'like', $like)->orWhere('v.sku', 'like', $like));
        }
        if (! empty($q['categoryId'])) {
            $qb->where('p.category_id', $q['categoryId']);
        }
        if (! empty($q['availability'])) {
            $qb->where('p.computed_availability', $q['availability']);
        }
        if (! empty($q['status'])) {
            $qb->where('p.status', $q['status']);
        }
        if (! empty($q['lowStock'])) {
            $qb->having('agg_low', '>', 0);
        }
        $total = DB::query()->fromSub((clone $qb), 'x')->count();
        $rows = $qb->orderByDesc('p.updated_at')->offset(Paginated::offset($q))->limit($q['limit'])->get();
        $thumbs = $rows->isNotEmpty()
            ? ProductImage::whereIn('product_id', $rows->pluck('id'))->where('is_main', true)->pluck('thumb_url', 'product_id')
            : collect();

        return Paginated::make($rows->map(fn ($p) => [
            'id' => (int) $p->id,
            'name' => $p->name,
            'reference' => $p->reference,
            'slug' => $p->slug,
            'status' => $p->status,
            'category' => $p->cat_id ? ['id' => (int) $p->cat_id, 'name' => $p->cat_name] : null,
            'basePrice' => (int) $p->base_price,
            'currentPrice' => (int) $p->current_price,
            'onSale' => (bool) $p->on_sale,
            'availabilityMode' => $p->availability_mode,
            'computedAvailability' => $p->computed_availability,
            'isFeatured' => (bool) $p->is_featured,
            'isNew' => (bool) $p->is_new,
            'isBestSeller' => (bool) $p->is_best_seller,
            'salesCount' => (int) $p->sales_count,
            'stockOnHand' => (int) $p->agg_on_hand,
            'stockAvailable' => (int) $p->agg_on_hand - (int) $p->agg_reserved,
            'variantCount' => (int) $p->agg_variants,
            'lowStock' => (int) $p->agg_low > 0,
            'thumbUrl' => $thumbs[$p->id] ?? null,
            'updatedAt' => Format::iso($p->updated_at),
        ])->all(), $total, $q);
    }

    public function get(int $id): array
    {
        $p = Product::with(['category', 'supplier', 'collections', 'relatedProducts', 'variants.attributeValues.attribute', 'images'])->find($id)
            ?? throw new HttpException(404, 'Produit introuvable');
        $variants = $p->variants->sort(fn ($a, $b) => ($a->sort_order <=> $b->sort_order) ?: ($a->id <=> $b->id))->values();
        $images = $p->images->sort(fn ($a, $b) => ($a->sort_order <=> $b->sort_order) ?: ($a->id <=> $b->id))->values();

        return $p->attributesToArrayCamel() + [
            'category' => $p->category?->toArray(),
            'supplier' => $p->supplier?->toArray(),
            'collections' => $p->collections->map(fn ($c) => $c->attributesToArrayCamel())->all(),
            'images' => $images->map(fn ($i) => $i->toArray())->all(),
            'collectionIds' => $p->collections->pluck('id')->all(),
            'relatedProductIds' => $p->relatedProducts->pluck('id')->all(),
            'relatedProducts' => $p->relatedProducts->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'reference' => $r->reference])->all(),
            'variants' => $variants->map(fn ($v) => $v->attributesToArrayCamel() + [
                'attributeValues' => $v->attributeValues->map(fn ($a) => $a->attributesToArrayCamel() + ['attribute' => $a->attribute?->attributesToArrayCamel()])->all(),
                'stockAvailable' => $v->stockAvailable(),
                'attributeValueIds' => $v->attributeValues->pluck('id')->all(),
            ])->all(),
        ];
    }

    private function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $root = Format::slugify($base) ?: 'produit';
        $slug = $root;
        for ($i = 2; Product::withTrashed()->where('slug', $slug)->when($excludeId, fn ($q) => $q->where('id', '<>', $excludeId))->exists(); $i++) {
            $slug = "{$root}-{$i}";
        }

        return $slug;
    }

    private function applyRelations(Product $p, array $dto): void
    {
        if (array_key_exists('collectionIds', $dto)) {
            $p->collections()->sync($dto['collectionIds'] ?? []);
        }
        if (array_key_exists('relatedProductIds', $dto)) {
            $p->relatedProducts()->sync(array_values(array_filter($dto['relatedProductIds'] ?? [], fn ($i) => $i !== $p->id)));
        }
    }

    private function assignScalars(Product $p, array $dto): void
    {
        foreach (self::PRODUCT_SCALARS as $k) {
            if (array_key_exists($k, $dto)) {
                $p->setAttribute(Str::snake($k), $dto[$k]);
            }
        }
        if (array_key_exists('description', $dto)) {
            $p->description = Html::sanitize($dto['description']);
        }
        if (array_key_exists('careInstructions', $dto)) {
            $p->care_instructions = Html::sanitize($dto['careInstructions']);
        }
        if (! empty($dto['status']) && $dto['status'] !== $p->status) {
            $p->status = $dto['status'];
            if ($dto['status'] === ProductStatus::PUBLISHED->value && ! $p->published_at) {
                $p->published_at = now();
            }
        }
    }

    public function create(AdminPrincipal $actor, array $dto, RequestMeta $meta): array
    {
        $this->assertPriceRights($actor, null, $dto, self::PRODUCT_PRICE_FIELDS);
        $effects = new StockEffects;
        $product = DB::transaction(function () use ($actor, $dto, $effects) {
            $category = ! empty($dto['categoryId']) ? Category::find($dto['categoryId']) : null;
            if (! empty($dto['categoryId']) && ! $category) {
                throw new HttpException(400, 'Catégorie introuvable');
            }
            $p = new Product(['lead_time_days' => 14, 'status' => ProductStatus::DRAFT->value]);
            $this->assignScalars($p, $dto);
            $p->slug = $this->uniqueSlug(! empty($dto['slug']) ? $dto['slug'] : $dto['name']);
            $p->reference = trim($dto['reference'] ?? '') ?: $this->numbering->productReference($category?->code ?? 'GEN');
            $p->current_price = $dto['basePrice'];
            $p->save();
            $this->applyRelations($p, $dto);
            $variant = ProductVariant::create([
                'product_id' => $p->id,
                'sku' => $p->reference,
                'name' => 'Standard',
                'price' => $dto['basePrice'],
                'compare_at_price' => $dto['compareAtPrice'] ?? null,
                'cost_price' => $dto['costPrice'] ?? null,
                'is_default' => true,
            ]);
            if (! empty($dto['initialStock'])) {
                $this->inventory->receive(['variantId' => $variant->id, 'quantity' => (int) $dto['initialStock']], ['adminUserId' => $actor->id], 'Stock initial', false, $effects);
            }

            return $p;
        });
        $this->inventory->flush($effects);
        $this->indexer->refresh([$product->id]);
        $this->audit->log($actor->id, 'product.create', 'product', $product->id, null, $dto, $meta);

        return $this->get($product->id);
    }

    public function update(AdminPrincipal $actor, int $id, array $dto, RequestMeta $meta): array
    {
        $p = Product::with('variants')->find($id) ?? throw new HttpException(404, 'Produit introuvable');
        $this->assertPriceRights($actor, $p, $dto, self::PRODUCT_PRICE_FIELDS);
        $before = $p->attributesToArrayCamel();
        $oldBase = $p->base_price;
        $this->assignScalars($p, $dto);
        if (! empty($dto['slug']) && $dto['slug'] !== $p->slug) {
            $p->slug = $this->uniqueSlug($dto['slug'], $id);
        }
        if (! empty($dto['reference']) && $dto['reference'] !== $p->reference) {
            $p->reference = trim($dto['reference']);
        }
        $p->save();
        $this->applyRelations($p, $dto);
        $variants = $p->variants;
        if (array_key_exists('basePrice', $dto) && $dto['basePrice'] !== $oldBase && $variants->count() === 1 && $variants[0]->price === $oldBase) {
            ProductVariant::whereKey($variants[0]->id)->update(['price' => $dto['basePrice']]);
        }
        $this->indexer->refresh([$id]);
        $priceChanged = collect(self::PRODUCT_PRICE_FIELDS)->contains(fn ($f) => array_key_exists($f, $dto) && $dto[$f] !== ($before[$f] ?? null));
        $this->audit->log($actor->id, $priceChanged ? 'product.update_price' : 'product.update', 'product', $id, $before, $dto, $meta);

        return $this->get($id);
    }

    public function remove(AdminPrincipal $actor, int $id, RequestMeta $meta): array
    {
        $p = Product::find($id) ?? throw new HttpException(404, 'Produit introuvable');
        $before = $p->attributesToArrayCamel();
        $p->update(['status' => ProductStatus::DRAFT->value]);
        $p->delete();
        $this->audit->log($actor->id, 'product.delete', 'product', $id, $before, null, $meta);

        return ['success' => true];
    }

    public function bulk(AdminPrincipal $actor, array $dto, RequestMeta $meta): array
    {
        $ids = $dto['ids'];
        if (! $ids) {
            return ['updated' => 0];
        }
        switch ($dto['action']) {
            case 'publish':
                Product::whereIn('id', $ids)->update(['status' => ProductStatus::PUBLISHED->value]);
                Product::whereIn('id', $ids)->whereNull('published_at')->update(['published_at' => now()]);
                break;
            case 'unpublish':
                Product::whereIn('id', $ids)->update(['status' => ProductStatus::DRAFT->value]);
                break;
            case 'setCategory':
                if (empty($dto['categoryId'])) {
                    throw new HttpException(400, 'Catégorie requise');
                }
                Product::whereIn('id', $ids)->update(['category_id' => $dto['categoryId']]);
                break;
            case 'applyPromotion':
                if (! $this->canEditPrices($actor)) {
                    throw new HttpException(403, "Vous n'avez pas le droit de modifier les prix");
                }
                if (empty($dto['percent'])) {
                    throw new HttpException(400, 'Pourcentage de remise requis');
                }
                $factor = 100 - (int) $dto['percent'];
                ProductVariant::whereIn('product_id', $ids)->update([
                    'promo_price' => DB::raw("ROUND(price * {$factor} / 100)"),
                    'promo_starts_at' => ! empty($dto['startsAt']) ? Carbon::parse($dto['startsAt']) : null,
                    'promo_ends_at' => ! empty($dto['endsAt']) ? Carbon::parse($dto['endsAt']) : null,
                ]);
                break;
            case 'clearPromotion':
                if (! $this->canEditPrices($actor)) {
                    throw new HttpException(403, "Vous n'avez pas le droit de modifier les prix");
                }
                ProductVariant::whereIn('product_id', $ids)->update(['promo_price' => null, 'promo_starts_at' => null, 'promo_ends_at' => null]);
                break;
            case 'delete':
                Product::whereIn('id', $ids)->update(['status' => ProductStatus::DRAFT->value]);
                Product::whereIn('id', $ids)->delete();
                break;
        }
        $this->indexer->refresh($ids);
        $this->audit->log($actor->id, "product.bulk_{$dto['action']}", 'product', implode(',', $ids), null, $dto, $meta);

        return ['updated' => count($ids)];
    }

    // ----- Variantes -------------------------------------------------------------

    private function ensureSingleDefault(int $productId, int $defaultId): void
    {
        ProductVariant::where('product_id', $productId)->where('id', '<>', $defaultId)->update(['is_default' => false]);
    }

    private function serializeVariant(ProductVariant $v): array
    {
        return $v->attributesToArrayCamel() + ['attributeValues' => $v->attributeValues->map(fn ($a) => $a->attributesToArrayCamel())->all()];
    }

    public function createVariant(AdminPrincipal $actor, int $productId, array $dto, RequestMeta $meta): array
    {
        $this->assertPriceRights($actor, null, $dto, self::VARIANT_PRICE_FIELDS);
        $product = Product::find($productId) ?? throw new HttpException(404, 'Produit introuvable');
        $effects = new StockEffects;
        $variant = DB::transaction(function () use ($actor, $productId, $product, $dto, $effects) {
            $count = ProductVariant::where('product_id', $productId)->count();
            $v = ProductVariant::create([
                'product_id' => $productId,
                'sku' => trim($dto['sku'] ?? '') ?: sprintf('%s-%02d', $product->reference, $count + 1),
                'name' => $dto['name'],
                'price' => $dto['price'],
                'compare_at_price' => $dto['compareAtPrice'] ?? null,
                'promo_price' => $dto['promoPrice'] ?? null,
                'promo_starts_at' => ! empty($dto['promoStartsAt']) ? Carbon::parse($dto['promoStartsAt']) : null,
                'promo_ends_at' => ! empty($dto['promoEndsAt']) ? Carbon::parse($dto['promoEndsAt']) : null,
                'cost_price' => $dto['costPrice'] ?? null,
                'low_stock_threshold' => $dto['lowStockThreshold'] ?? 3,
                'availability_mode' => $dto['availabilityMode'] ?? null,
                'is_default' => $dto['isDefault'] ?? $count === 0,
                'is_active' => $dto['isActive'] ?? true,
                'sort_order' => $dto['sortOrder'] ?? $count,
            ]);
            if (! empty($dto['attributeValueIds'])) {
                $v->attributeValues()->sync($dto['attributeValueIds']);
            }
            if ($v->is_default) {
                $this->ensureSingleDefault($productId, $v->id);
            }
            if (! empty($dto['initialStock'])) {
                $this->inventory->receive(['variantId' => $v->id, 'quantity' => (int) $dto['initialStock']], ['adminUserId' => $actor->id], 'Stock initial', false, $effects);
            }

            return $v;
        });
        $effects->addProduct($productId);
        $this->inventory->flush($effects);
        $this->audit->log($actor->id, 'variant.create', 'product_variant', $variant->id, null, $dto, $meta);

        return $this->serializeVariant($variant->refresh()->load('attributeValues'));
    }

    public function updateVariant(AdminPrincipal $actor, int $variantId, array $dto, RequestMeta $meta): array
    {
        $v = ProductVariant::with('attributeValues')->find($variantId) ?? throw new HttpException(404, 'Variante introuvable');
        $this->assertPriceRights($actor, $v, $dto, self::VARIANT_PRICE_FIELDS);
        $before = $v->attributesToArrayCamel();
        foreach (['sku', 'name', 'price', 'compareAtPrice', 'promoPrice', 'costPrice', 'lowStockThreshold', 'isDefault', 'isActive', 'sortOrder'] as $k) {
            if (array_key_exists($k, $dto)) {
                $v->setAttribute(Str::snake($k), $dto[$k]);
            }
        }
        if (array_key_exists('availabilityMode', $dto)) {
            $v->availability_mode = $dto['availabilityMode'];
            $v->auto_switched = false;
        }
        foreach (['promoStartsAt' => 'promo_starts_at', 'promoEndsAt' => 'promo_ends_at'] as $k => $col) {
            if (array_key_exists($k, $dto)) {
                $v->{$col} = $dto[$k] ? Carbon::parse($dto[$k]) : null;
            }
        }
        DB::transaction(function () use ($v, $dto) {
            $v->save();
            if (array_key_exists('attributeValueIds', $dto)) {
                $v->attributeValues()->sync($dto['attributeValueIds'] ?? []);
            }
            if ($v->is_default) {
                $this->ensureSingleDefault($v->product_id, $v->id);
            }
        });
        $this->indexer->refresh([$v->product_id]);
        $priceChanged = collect(self::VARIANT_PRICE_FIELDS)->contains(fn ($f) => array_key_exists($f, $dto));
        $this->audit->log($actor->id, $priceChanged ? 'variant.update_price' : 'variant.update', 'product_variant', $v->id, $before, $dto, $meta);

        return $this->serializeVariant($v->load('attributeValues'));
    }

    public function removeVariant(AdminPrincipal $actor, int $variantId, RequestMeta $meta): array
    {
        $v = ProductVariant::find($variantId) ?? throw new HttpException(404, 'Variante introuvable');
        if ($v->stock_reserved > 0) {
            throw new HttpException(400, 'Cette variante a du stock réservé par des commandes : désactivez-la plutôt.');
        }
        if (ProductVariant::where('product_id', $v->product_id)->count() <= 1) {
            throw new HttpException(400, 'Un produit doit conserver au moins une variante');
        }
        $before = $v->attributesToArrayCamel();
        $v->update(['is_active' => false, 'sku' => mb_substr("{$v->sku}-del-{$v->id}", 0, 60)]);
        $this->audit->log($actor->id, 'variant.deactivate', 'product_variant', $v->id, $before, null, $meta);
        $this->indexer->refresh([$v->product_id]);

        return ['success' => true];
    }

    /** Génère toutes les combinaisons d'attributs sélectionnés. */
    public function generateVariants(AdminPrincipal $actor, int $productId, array $dto, RequestMeta $meta): array
    {
        $product = Product::with('variants.attributeValues')->find($productId) ?? throw new HttpException(404, 'Produit introuvable');
        $values = AttributeValue::with('attribute')->whereIn('id', $dto['attributeValueIds'])->get();
        $groups = $values->groupBy('attribute_id');
        $combos = [[]];
        foreach ($groups as $group) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($group as $g) {
                    $next[] = [...$c, $g];
                }
            }
            $combos = $next;
        }
        $combos = array_filter($combos, fn ($c) => count($c) > 0);
        $key = function (iterable $vals) {
            $ids = collect($vals)->pluck('id')->sort()->values()->all();

            return implode('-', $ids);
        };
        $existing = $product->variants->map(fn ($v) => $key($v->attributeValues))->flip();
        $created = [];
        foreach ($combos as $combo) {
            if (isset($existing[$key($combo)])) {
                continue;
            }
            $created[] = $this->createVariant($actor, $productId, [
                'name' => implode(' / ', array_map(fn ($a) => $a->value, $combo)),
                'price' => $dto['price'] ?? $product->base_price,
                'attributeValueIds' => array_map(fn ($a) => $a->id, $combo),
                'isDefault' => false,
            ], $meta);
        }
        $standard = $product->variants->first(fn ($v) => $v->attributeValues->isEmpty() && $v->stock_on_hand === 0 && $v->stock_reserved === 0);
        if ($created && $standard) {
            ProductVariant::whereKey($standard->id)->update(['is_active' => false, 'is_default' => false, 'sku' => mb_substr("{$standard->sku}-old-{$standard->id}", 0, 60)]);
            ProductVariant::whereKey($created[0]['id'])->update(['is_default' => true]);
        }
        $this->indexer->refresh([$productId]);

        return ['created' => count($created)];
    }

    // ----- Images ------------------------------------------------------------------

    public function addImage(int $productId, array $dto): array
    {
        if (! Product::whereKey($productId)->exists()) {
            throw new HttpException(404, 'Produit introuvable');
        }
        $count = ProductImage::where('product_id', $productId)->count();
        $img = ProductImage::create([
            'product_id' => $productId,
            'variant_id' => $dto['variantId'] ?? null,
            'url' => $dto['url'],
            'medium_url' => $dto['mediumUrl'],
            'thumb_url' => $dto['thumbUrl'],
            'alt' => $dto['alt'] ?? '',
            'sort_order' => $count,
            'is_main' => $dto['isMain'] ?? $count === 0,
        ]);
        if ($img->is_main) {
            ProductImage::where('product_id', $productId)->where('id', '<>', $img->id)->update(['is_main' => false]);
        }

        return $img->refresh()->toArray();
    }

    public function updateImage(int $imageId, array $dto): array
    {
        $img = ProductImage::find($imageId) ?? throw new HttpException(404, 'Image introuvable');
        if (array_key_exists('alt', $dto)) {
            $img->alt = $dto['alt'] ?? '';
        }
        if (array_key_exists('variantId', $dto)) {
            $img->variant_id = $dto['variantId'];
        }
        if (! empty($dto['isMain'])) {
            $img->is_main = true;
            ProductImage::where('product_id', $img->product_id)->where('id', '<>', $img->id)->update(['is_main' => false]);
        }
        $img->save();

        return $img->toArray();
    }

    public function reorderImages(int $productId, array $ids): array
    {
        DB::transaction(function () use ($productId, $ids) {
            foreach (array_values($ids) as $index => $id) {
                ProductImage::where('id', $id)->where('product_id', $productId)->update(['sort_order' => $index]);
            }
        });

        return ProductImage::where('product_id', $productId)->orderBy('sort_order')->get()->toArray();
    }

    public function removeImage(int $imageId): array
    {
        $img = ProductImage::find($imageId) ?? throw new HttpException(404, 'Image introuvable');
        $img->delete();
        if ($img->is_main) {
            ProductImage::where('product_id', $img->product_id)->orderBy('sort_order')->first()?->update(['is_main' => true]);
        }

        return ['success' => true];
    }

    // ----- Import / export CSV -------------------------------------------------------

    public function exportCsv(): string
    {
        $lines = [];
        foreach (Product::with(['category', 'variants'])->orderBy('reference')->get() as $p) {
            foreach ($p->variants->isNotEmpty() ? $p->variants : [null] as $v) {
                $lines[] = [
                    $p->reference, $p->name, $p->category?->code ?? '', $p->status, $p->availability_mode, $p->base_price, $p->compare_at_price, $p->cost_price,
                    $p->lead_time_days, $p->material, $p->dimensions, $p->short_description, $v?->sku ?? '', $v?->name ?? '', $v?->price ?? '',
                    $v?->stock_on_hand ?? '', $v?->low_stock_threshold ?? '',
                ];
            }
        }

        return Csv::make(self::CSV_HEADERS, $lines);
    }

    public function importCsv(AdminPrincipal $actor, string $content, RequestMeta $meta): array
    {
        if (! $this->canEditPrices($actor)) {
            throw new HttpException(403, "L'import CSV nécessite le droit de modifier les prix");
        }
        $rows = Csv::parse($content);
        $errors = [];
        $created = 0;
        $updated = 0;
        $effects = new StockEffects;
        $categories = Category::all();
        $num = fn ($v) => $v === '' || $v === null ? null : (int) $v;
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            try {
                if (empty($row['name']) || empty($row['base_price'])) {
                    throw new \RuntimeException('nom et prix de base obligatoires');
                }
                $category = $categories->first(fn ($c) => $c->code === strtoupper($row['category_code'] ?? ''));
                $isNew = DB::transaction(function () use ($row, $category, $num, $actor, $effects) {
                    $p = ! empty($row['reference']) ? Product::where('reference', $row['reference'])->first() : null;
                    $isNew = ! $p;
                    $p ??= new Product(['lead_time_days' => 14]);
                    $p->name = $row['name'];
                    $p->category_id = $category?->id ?? $p->category_id;
                    $p->status = in_array($row['status'] ?? '', ProductStatus::values(), true) ? $row['status'] : ($p->status ?? ProductStatus::DRAFT->value);
                    $p->availability_mode = in_array($row['availability'] ?? '', AvailabilityMode::values(), true)
                        ? $row['availability'] : ($p->availability_mode ?? AvailabilityMode::IN_STOCK->value);
                    $p->base_price = (int) $row['base_price'];
                    $p->compare_at_price = $num($row['compare_at_price'] ?? '');
                    $p->cost_price = $num($row['cost_price'] ?? '');
                    $p->lead_time_days = $num($row['lead_time_days'] ?? '') ?? $p->lead_time_days;
                    $p->material = ($row['material'] ?? '') ?: null;
                    $p->dimensions = ($row['dimensions'] ?? '') ?: null;
                    $p->short_description = ($row['short_description'] ?? '') ?: null;
                    if ($p->status === ProductStatus::PUBLISHED->value && ! $p->published_at) {
                        $p->published_at = now();
                    }
                    if ($isNew) {
                        $p->slug = $this->uniqueSlug($row['name']);
                        $p->reference = ($row['reference'] ?? '') ?: $this->numbering->productReference($category?->code ?? 'GEN');
                        $p->current_price = $p->base_price;
                    }
                    $p->save();
                    $sku = ($row['sku'] ?? '') ?: $p->reference;
                    $v = ProductVariant::where('sku', $sku)->first();
                    if (! $v) {
                        $v = ProductVariant::create([
                            'product_id' => $p->id, 'sku' => $sku, 'name' => ($row['variant_name'] ?? '') ?: 'Standard',
                            'price' => $num($row['variant_price'] ?? '') ?? $p->base_price, 'is_default' => $isNew,
                        ])->refresh();
                    } else {
                        $v->update([
                            'name' => ($row['variant_name'] ?? '') ?: $v->name,
                            'price' => $num($row['variant_price'] ?? '') ?? $v->price,
                            'low_stock_threshold' => $num($row['low_stock_threshold'] ?? '') ?? $v->low_stock_threshold,
                        ]);
                    }
                    $target = $num($row['stock_on_hand'] ?? '');
                    if ($target !== null && $target !== $v->stock_on_hand) {
                        if ($target < $v->stock_reserved) {
                            throw new \RuntimeException("stock inférieur au réservé ({$v->stock_reserved})");
                        }
                        $delta = $target - $v->stock_on_hand;
                        if ($delta > 0) {
                            $this->inventory->receive(['variantId' => $v->id, 'quantity' => $delta], ['adminUserId' => $actor->id], 'Import CSV', false, $effects);
                        } else {
                            ProductVariant::whereKey($v->id)->update(['stock_on_hand' => $target]);
                        }
                    }
                    $effects->addProduct($p->id);

                    return $isNew;
                });
                $isNew ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $errors[] = "Ligne {$line} : {$e->getMessage()}";
            }
        }
        $this->inventory->flush($effects);
        $this->audit->log($actor->id, 'product.import_csv', 'product', null, null, ['created' => $created, 'updated' => $updated, 'errors' => count($errors)], $meta);

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }
}
