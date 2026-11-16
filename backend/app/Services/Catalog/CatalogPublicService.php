<?php

namespace App\Services\Catalog;

use App\Enums\ProductStatus;
use App\Enums\ReviewStatus;
use App\Models\BackInStockAlert;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Services\SettingsService;
use App\Support\Format;
use App\Support\Paginated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CatalogPublicService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function catalogSettings(): array
    {
        return [
            'maxPreorderQty' => (int) $this->settings->get('preorder')['maxQtyPerOrder'],
            'depositPercent' => (int) $this->settings->get('payments')['depositPercent'],
        ];
    }

    private function mapCategory(Category $c): array
    {
        return [
            'id' => $c->id, 'parentId' => $c->parent_id, 'name' => $c->name, 'slug' => $c->slug, 'code' => $c->code,
            'description' => $c->description, 'imageUrl' => $c->image_url, 'seoTitle' => $c->seo_title, 'seoDescription' => $c->seo_description,
        ];
    }

    public function categoryTree(): array
    {
        $all = Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $nodes = [];
        foreach ($all as $c) {
            $nodes[$c->id] = $this->mapCategory($c) + ['children' => []];
        }
        // Construction de l'arbre par références (ordre de tri préservé).
        $roots = [];
        foreach ($all as $c) {
            if ($c->parent_id && isset($nodes[$c->parent_id])) {
                $nodes[$c->parent_id]['children'][] = &$nodes[$c->id];
            } else {
                $roots[] = &$nodes[$c->id];
            }
        }

        return json_decode(json_encode($roots), true);
    }

    /** @return list<int> */
    private function descendantIds(int $rootId): array
    {
        $all = Category::where('is_active', true)->get(['id', 'parent_id']);
        $ids = [$rootId];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($all as $c) {
                if ($c->parent_id === $ids[$i]) {
                    $ids[] = $c->id;
                }
            }
        }

        return $ids;
    }

    public function categoryBySlug(string $slug): array
    {
        $category = Category::where('slug', $slug)->where('is_active', true)->first() ?? throw new HttpException(404, 'Catégorie introuvable');
        $children = Category::where('parent_id', $category->id)->where('is_active', true)->orderBy('sort_order')->get();

        return $this->mapCategory($category) + [
            'children' => $children->map(fn ($c) => $this->mapCategory($c))->all(),
            'breadcrumbs' => array_map(fn ($c) => $c->toArray(), $this->breadcrumbs($category)),
        ];
    }

    /** @return list<Category> */
    public function breadcrumbs(?Category $category): array
    {
        $trail = [];
        $current = $category;
        while ($current) {
            array_unshift($trail, $current);
            $current = $current->parent_id ? Category::find($current->parent_id) : null;
            if (count($trail) > 6) {
                break;
            }
        }

        return $trail;
    }

    private function activeCollections(): Builder
    {
        return Collection::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', DB::raw('NOW()')))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', DB::raw('NOW()')));
    }

    private function mapCollection(Collection $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description, 'imageUrl' => $c->image_url,
            'startsAt' => Format::iso($c->starts_at), 'endsAt' => Format::iso($c->ends_at), 'isFeatured' => $c->is_featured,
        ];
    }

    public function listCollections(): array
    {
        return $this->activeCollections()->orderBy('sort_order')->get()->map(fn ($c) => $this->mapCollection($c))->all();
    }

    public function collectionBySlug(string $slug): array
    {
        $c = $this->activeCollections()->where('slug', $slug)->first() ?? throw new HttpException(404, 'Collection introuvable');

        return $this->mapCollection($c) + ['seoTitle' => $c->seo_title, 'seoDescription' => $c->seo_description];
    }

    private function applyFilters(Builder $qb, array $q): void
    {
        $qb->where('p.status', ProductStatus::PUBLISHED->value)->whereNull('p.deleted_at');
        if (! empty($q['category'])) {
            $cat = Category::where('slug', $q['category'])->where('is_active', true)->first() ?? throw new HttpException(404, 'Catégorie introuvable');
            $qb->whereIn('p.category_id', $this->descendantIds($cat->id));
        }
        if (! empty($q['collection'])) {
            $qb->whereExists(fn ($s) => $s->select(DB::raw(1))->from('product_collections as pc')->join('collections as col', 'col.id', '=', 'pc.collection_id')
                ->whereColumn('pc.product_id', 'p.id')->where('col.slug', $q['collection']));
        }
        if (! empty($q['q'])) {
            $like = "%{$q['q']}%";
            $qb->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('p.reference', 'like', $like)
                ->orWhere('p.short_description', 'like', $like)->orWhere('p.material', 'like', $like));
        }
        if (isset($q['minPrice'])) {
            $qb->where('p.current_price', '>=', $q['minPrice']);
        }
        if (isset($q['maxPrice'])) {
            $qb->where('p.current_price', '<=', $q['maxPrice']);
        }
        if (! empty($q['material'])) {
            $qb->where('p.material', $q['material']);
        }
        if (! empty($q['availability'])) {
            $qb->where('p.computed_availability', $q['availability']);
        }
        foreach (['onSale' => 'on_sale', 'isNew' => 'is_new', 'bestSeller' => 'is_best_seller', 'featured' => 'is_featured'] as $k => $col) {
            if (! empty($q[$k])) {
                $qb->where("p.{$col}", true);
            }
        }
        if (! empty($q['color'])) {
            $colors = array_slice(array_values(array_filter(array_map('trim', explode(',', $q['color'])))), 0, 10);
            if ($colors) {
                $qb->where(function ($w) use ($colors) {
                    $w->whereExists(fn ($s) => $s->select(DB::raw(1))->from('product_variants as v2')
                        ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'v2.id')
                        ->join('attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
                        ->join('attributes as at2', 'at2.id', '=', 'av.attribute_id')
                        ->whereColumn('v2.product_id', 'p.id')->where('v2.is_active', true)->where('at2.type', 'COLOR')->whereIn('av.value', $colors));
                    foreach ($colors as $c) {
                        $w->orWhere('p.colors', 'like', '%"'.str_replace(['%', '_', '"'], '', $c).'"%');
                    }
                });
            }
        }
    }

    private function baseQuery(): Builder
    {
        return Product::withTrashed()->from('products as p');
    }

    /** @param  list<int>  $ids */
    public function loadCards(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = Product::with(['category', 'variants'])->whereIn('id', $ids)->get()->keyBy('id');
        $imgs = ProductImage::whereIn('product_id', $ids)->orderBy('sort_order')->get()->groupBy('product_id');
        $out = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $out[] = CatalogMapper::card($rows[$id], $imgs[$id] ?? []);
            }
        }

        return $out;
    }

    public function listProducts(array $q): array
    {
        $limit = min((int) ($q['limit'] ?? 20), 48);
        $page = (int) ($q['page'] ?? 1);
        $qb = $this->baseQuery();
        $this->applyFilters($qb, $q);
        $total = (clone $qb)->count();
        $qb->select('p.id');
        switch ($q['sort'] ?? null) {
            case 'newest': $qb->orderByDesc('p.published_at')->orderByDesc('p.id');
                break;
            case 'price_asc': $qb->orderBy('p.current_price')->orderBy('p.id');
                break;
            case 'price_desc': $qb->orderByDesc('p.current_price')->orderByDesc('p.id');
                break;
            case 'best_sellers': $qb->orderByDesc('p.sales_count')->orderByDesc('p.id');
                break;
            default:
                if (! empty($q['q'])) {
                    $qb->orderByRaw('CASE WHEN p.name LIKE ? THEN 0 ELSE 1 END ASC', ["{$q['q']}%"]);
                } else {
                    $qb->orderByRaw("CASE WHEN p.computed_availability = 'UNAVAILABLE' THEN 1 ELSE 0 END ASC");
                }
                $qb->orderByDesc('p.is_featured')->orderByDesc('p.sales_count')->orderByDesc('p.id');
        }
        $ids = $qb->offset(($page - 1) * $limit)->limit($limit)->pluck('p.id')->map(fn ($id) => (int) $id)->all();

        return Paginated::make($this->loadCards($ids), $total, ['page' => $page, 'limit' => $limit]);
    }

    public function facets(array $q): array
    {
        $base = $this->baseQuery();
        $this->applyFilters($base, array_diff_key($q, array_flip(['color', 'material', 'minPrice', 'maxPrice'])));
        $ids = $base->pluck('p.id')->map(fn ($id) => (int) $id)->all();
        if (! $ids) {
            return ['colors' => [], 'materials' => [], 'priceRange' => ['min' => 0, 'max' => 0]];
        }
        $colors = DB::table('product_variants as v')
            ->join('variant_attribute_values as vav', 'vav.variant_id', '=', 'v.id')
            ->join('attribute_values as av', 'av.id', '=', 'vav.attribute_value_id')
            ->join('attributes as a', 'a.id', '=', 'av.attribute_id')
            ->where('a.type', 'COLOR')->where('v.is_active', true)->whereIn('v.product_id', $ids)
            ->groupBy('av.value')->select('av.value', DB::raw('MAX(av.hex_color) AS hexColor'))->get();
        $colorSet = [];
        foreach ($colors as $c) {
            $colorSet[$c->value] = $c->hexColor;
        }
        foreach (Product::whereIn('id', $ids)->get(['id', 'colors']) as $p) {
            foreach ($p->colors ?? [] as $c) {
                if (! array_key_exists($c, $colorSet)) {
                    $colorSet[$c] = null;
                }
            }
        }
        $materials = Product::whereIn('id', $ids)->whereNotNull('material')->distinct()->pluck('material')->sort()->values()->all();
        $range = Product::whereIn('id', $ids)->selectRaw('MIN(current_price) AS min, MAX(current_price) AS max')->first();

        return [
            'colors' => array_map(fn ($v, $h) => ['value' => (string) $v, 'hexColor' => $h], array_keys($colorSet), $colorSet),
            'materials' => $materials,
            'priceRange' => ['min' => (int) ($range->min ?? 0), 'max' => (int) ($range->max ?? 0)],
        ];
    }

    public function productBySlug(string $slug): array
    {
        $p = Product::with(['category', 'variants.attributeValues.attribute', 'images'])
            ->where('slug', $slug)->where('status', ProductStatus::PUBLISHED->value)->first()
            ?? throw new HttpException(404, 'Produit introuvable');
        $rating = Review::where('product_id', $p->id)->where('status', ReviewStatus::APPROVED->value)
            ->selectRaw('AVG(rating) AS avg, COUNT(id) AS count')->first();

        return CatalogMapper::detail($p, $this->catalogSettings(), $this->breadcrumbs($p->category)) + [
            'rating' => ['average' => $rating?->avg !== null ? round((float) $rating->avg, 1) : null, 'count' => (int) ($rating?->count ?? 0)],
        ];
    }

    public function related(string $slug, int $limit = 8): array
    {
        $p = Product::with('relatedProducts')->where('slug', $slug)->first() ?? throw new HttpException(404, 'Produit introuvable');
        $ids = $p->relatedProducts->filter(fn ($r) => $r->status === ProductStatus::PUBLISHED->value && ! $r->deleted_at)->pluck('id')->all();
        if (count($ids) < $limit && $p->category_id) {
            $same = Product::where('category_id', $p->category_id)->where('id', '<>', $p->id)->where('status', ProductStatus::PUBLISHED->value)
                ->when($ids, fn ($q) => $q->whereNotIn('id', $ids))
                ->orderByDesc('sales_count')->limit($limit - count($ids))->pluck('id')->all();
            $ids = [...$ids, ...$same];
        }

        return $this->loadCards(array_slice($ids, 0, $limit));
    }

    public function search(?string $q): array
    {
        $term = trim((string) $q);
        if (mb_strlen($term) < 2) {
            return ['products' => [], 'categories' => []];
        }
        $result = $this->listProducts(['q' => $term, 'page' => 1, 'limit' => 8]);
        $cats = Category::where('is_active', true)->where('name', 'like', "%{$term}%")->limit(5)->get();

        return [
            'products' => $result['data'],
            'total' => $result['meta']['total'],
            'categories' => $cats->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])->all(),
        ];
    }

    public function subscribeBackInStock(int $variantId, array $dto): array
    {
        if (! ProductVariant::whereKey($variantId)->exists()) {
            throw new HttpException(404, 'Article introuvable');
        }
        $phone = ! empty($dto['phone']) ? Format::normalizeGabonPhone($dto['phone']) : null;
        if (! empty($dto['phone']) && ! $phone) {
            throw new HttpException(400, 'Numéro gabonais invalide');
        }
        $email = ! empty($dto['email']) ? strtolower($dto['email']) : null;
        if (! $email && ! $phone) {
            throw new HttpException(400, 'Indiquez un e-mail ou un téléphone');
        }
        $exists = BackInStockAlert::where('variant_id', $variantId)->whereNull('notified_at')
            ->where(fn ($w) => $w->when($email, fn ($q) => $q->orWhere('email', $email))->when($phone, fn ($q) => $q->orWhere('phone', $phone)))
            ->exists();
        if (! $exists) {
            BackInStockAlert::create(['variant_id' => $variantId, 'email' => $email, 'phone' => $phone]);
        }

        return ['message' => 'C’est noté ! Nous vous préviendrons dès le retour en stock.'];
    }

    public function productReviews(string $slug): array
    {
        $p = Product::where('slug', $slug)->first(['id']) ?? throw new HttpException(404, 'Produit introuvable');

        return Review::where('product_id', $p->id)->where('status', ReviewStatus::APPROVED->value)->orderByDesc('created_at')->limit(50)->get()
            ->map(fn ($r) => ['id' => $r->id, 'authorName' => $r->author_name, 'rating' => $r->rating, 'comment' => $r->comment, 'createdAt' => Format::iso($r->created_at)])->all();
    }

    public function homeReviews(): array
    {
        return Review::with('product')->where('status', ReviewStatus::APPROVED->value)->orderByDesc('rating')->orderByDesc('created_at')->limit(6)->get()
            ->map(fn ($r) => ['id' => $r->id, 'authorName' => $r->author_name, 'rating' => $r->rating, 'comment' => $r->comment, 'productName' => $r->product?->name])->all();
    }
}
