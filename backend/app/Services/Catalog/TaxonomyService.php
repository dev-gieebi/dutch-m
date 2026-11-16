<?php

namespace App\Services\Catalog;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Support\AdminPrincipal;
use App\Support\Format;
use App\Support\RequestMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Catégories, collections, attributs et fournisseurs. */
class TaxonomyService
{
    public function __construct(private readonly AuditService $audit) {}

    private static function fill(object $model, array $dto, array $except = []): void
    {
        foreach ($dto as $k => $v) {
            if (! in_array($k, $except, true)) {
                $model->setAttribute(Str::snake($k), $v);
            }
        }
    }

    // ----- Catégories ------------------------------------------------------------

    public function listCategories(): array
    {
        $counts = Product::withTrashed()->whereNull('deleted_at')->groupBy('category_id')->selectRaw('category_id, COUNT(id) AS c')->pluck('c', 'category_id');

        return Category::orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($c) => $c->toArray() + ['productCount' => (int) ($counts[$c->id] ?? 0)])->all();
    }

    private function assertNoCycle(int $id, ?int $parentId): void
    {
        $all = Category::withTrashed()->pluck('parent_id', 'id');
        $current = $parentId;
        for ($depth = 0; $current !== null && $depth < 50; $depth++) {
            if ($current === $id) {
                throw new HttpException(400, 'Une catégorie ne peut pas être son propre parent');
            }
            $current = $all[$current] ?? null;
        }
    }

    public function createCategory(AdminPrincipal $actor, array $dto, RequestMeta $meta): array
    {
        $c = new Category;
        self::fill($c, $dto);
        $c->slug = ! empty($dto['slug']) ? $dto['slug'] : Format::slugify($dto['name']);
        $c->parent_id = $dto['parentId'] ?? null;
        $c->save();
        $c->refresh();
        $this->audit->log($actor->id, 'category.create', 'category', $c->id, null, $c, $meta);

        return $c->toArray();
    }

    public function updateCategory(AdminPrincipal $actor, int $id, array $dto, RequestMeta $meta): array
    {
        $c = Category::find($id) ?? throw new HttpException(404, 'Catégorie introuvable');
        if (array_key_exists('parentId', $dto)) {
            $this->assertNoCycle($id, $dto['parentId']);
        }
        $before = $c->attributesToArrayCamel();
        self::fill($c, $dto);
        $c->save();
        $this->audit->log($actor->id, 'category.update', 'category', $id, $before, $c, $meta);

        return $c->toArray();
    }

    public function saveTree(AdminPrincipal $actor, array $nodes, RequestMeta $meta): array
    {
        foreach ($nodes as $n) {
            $this->assertNoCycle((int) $n['id'], $n['parentId'] ?? null);
        }
        DB::transaction(function () use ($nodes) {
            foreach ($nodes as $n) {
                Category::whereKey($n['id'])->update(['parent_id' => $n['parentId'] ?? null, 'sort_order' => $n['sortOrder']]);
            }
        });
        $this->audit->log($actor->id, 'category.reorder', 'category', null, null, $nodes, $meta);

        return $this->listCategories();
    }

    public function removeCategory(AdminPrincipal $actor, int $id, RequestMeta $meta): array
    {
        if (Product::where('category_id', $id)->exists()) {
            throw new HttpException(409, 'Cette catégorie contient des produits : déplacez-les avant de la supprimer');
        }
        if (Category::where('parent_id', $id)->exists()) {
            throw new HttpException(409, 'Cette catégorie contient des sous-catégories');
        }
        Category::whereKey($id)->delete();
        $this->audit->log($actor->id, 'category.delete', 'category', $id, null, null, $meta);

        return ['success' => true];
    }

    // ----- Collections -----------------------------------------------------------

    public function listCollections(): array
    {
        return Collection::withCount('products')->orderBy('sort_order')->get()
            ->map(function ($c) {
                $arr = $c->toArray();
                unset($arr['productsCount']);

                return $arr + ['productCount' => (int) $c->products_count];
            })->all();
    }

    public function getCollection(int $id): array
    {
        $c = Collection::with('products')->find($id) ?? throw new HttpException(404, 'Collection introuvable');

        return $c->attributesToArrayCamel() + [
            'productIds' => $c->products->pluck('id')->all(),
            'products' => $c->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'reference' => $p->reference])->all(),
        ];
    }

    private function applyCollection(Collection $c, array $dto): void
    {
        self::fill($c, $dto, ['productIds', 'startsAt', 'endsAt']);
        foreach (['startsAt' => 'starts_at', 'endsAt' => 'ends_at'] as $k => $col) {
            if (array_key_exists($k, $dto)) {
                $c->{$col} = $dto[$k] ? Carbon::parse($dto[$k]) : null;
            }
        }
    }

    public function createCollection(AdminPrincipal $actor, array $dto, RequestMeta $meta): array
    {
        $c = new Collection(['slug' => ! empty($dto['slug']) ? $dto['slug'] : Format::slugify($dto['name'])]);
        $this->applyCollection($c, $dto);
        $c->save();
        $c->products()->sync($dto['productIds'] ?? []);
        $this->audit->log($actor->id, 'collection.create', 'collection', $c->id, null, $dto, $meta);

        return $this->getCollection($c->id);
    }

    public function updateCollection(AdminPrincipal $actor, int $id, array $dto, RequestMeta $meta): array
    {
        $c = Collection::find($id) ?? throw new HttpException(404, 'Collection introuvable');
        $this->applyCollection($c, $dto);
        $c->save();
        if (array_key_exists('productIds', $dto)) {
            $c->products()->sync($dto['productIds'] ?? []);
        }
        $this->audit->log($actor->id, 'collection.update', 'collection', $id, null, $dto, $meta);

        return $this->getCollection($id);
    }

    public function removeCollection(AdminPrincipal $actor, int $id, RequestMeta $meta): array
    {
        Collection::whereKey($id)->delete();
        $this->audit->log($actor->id, 'collection.delete', 'collection', $id, null, null, $meta);

        return ['success' => true];
    }

    // ----- Attributs ----------------------------------------------------------------

    public function listAttributes(): array
    {
        return Attribute::with(['values' => fn ($q) => $q->orderBy('sort_order')])->orderBy('sort_order')->get()->toArray();
    }

    public function createAttribute(array $dto): array
    {
        $a = new Attribute;
        self::fill($a, $dto);
        $a->save();

        return $a->refresh()->toArray();
    }

    public function updateAttribute(int $id, array $dto): array
    {
        $a = Attribute::find($id) ?? throw new HttpException(404, 'Attribut introuvable');
        self::fill($a, $dto);
        $a->save();

        return $a->toArray();
    }

    public function removeAttribute(int $id): array
    {
        Attribute::whereKey($id)->delete();

        return ['success' => true];
    }

    public function addValue(int $attributeId, array $dto): array
    {
        if (! Attribute::whereKey($attributeId)->exists()) {
            throw new HttpException(404, 'Attribut introuvable');
        }
        if (AttributeValue::where('attribute_id', $attributeId)->where('value', $dto['value'])->exists()) {
            throw new HttpException(409, 'Cette valeur existe déjà');
        }
        $v = new AttributeValue(['attribute_id' => $attributeId]);
        self::fill($v, $dto);
        $v->save();

        return $v->refresh()->toArray();
    }

    public function updateValue(int $id, array $dto): array
    {
        $v = AttributeValue::find($id) ?? throw new HttpException(404, 'Valeur introuvable');
        if ($dto['value'] !== $v->value && AttributeValue::where('attribute_id', $v->attribute_id)->where('value', $dto['value'])->where('id', '<>', $id)->exists()) {
            throw new HttpException(409, 'Cette valeur existe déjà');
        }
        self::fill($v, $dto);
        $v->save();

        return $v->toArray();
    }

    public function removeValue(int $id): array
    {
        AttributeValue::whereKey($id)->delete();

        return ['success' => true];
    }

    // ----- Fournisseurs ----------------------------------------------------------------

    public function listSuppliers(): array
    {
        $counts = Product::whereNotNull('supplier_id')->groupBy('supplier_id')->selectRaw('supplier_id, COUNT(id) AS c')->pluck('c', 'supplier_id');

        return Supplier::orderBy('name')->get()->map(fn ($s) => $s->toArray() + ['productCount' => (int) ($counts[$s->id] ?? 0)])->all();
    }

    public function getSupplier(int $id): array
    {
        $s = Supplier::find($id) ?? throw new HttpException(404, 'Fournisseur introuvable');
        $products = Product::where('supplier_id', $id)->get(['id', 'name', 'reference', 'status'])->map(fn ($p) => $p->attributesToArrayCamel())->all();
        $arrivals = array_map(fn ($r) => [
            'id' => (int) $r->id, 'reference' => $r->reference, 'currentStep' => $r->current_step,
            'shippedAt' => Format::iso($r->shipped_at), 'estimatedArrivalAt' => Format::iso($r->estimated_arrival_at), 'arrivedAt' => Format::iso($r->arrived_at),
        ], DB::select('SELECT id, reference, current_step, shipped_at, estimated_arrival_at, arrived_at FROM arrivals WHERE supplier_id = ? ORDER BY created_at DESC LIMIT 50', [$id]));

        return $s->toArray() + ['products' => $products, 'arrivals' => $arrivals];
    }

    public function createSupplier(AdminPrincipal $actor, array $dto, RequestMeta $meta): array
    {
        $s = new Supplier;
        self::fill($s, $dto);
        $s->save();
        $this->audit->log($actor->id, 'supplier.create', 'supplier', $s->id, null, $dto, $meta);

        return $s->refresh()->toArray();
    }

    public function updateSupplier(AdminPrincipal $actor, int $id, array $dto, RequestMeta $meta): array
    {
        $s = Supplier::find($id) ?? throw new HttpException(404, 'Fournisseur introuvable');
        $before = $s->attributesToArrayCamel();
        self::fill($s, $dto);
        $s->save();
        $this->audit->log($actor->id, 'supplier.update', 'supplier', $id, $before, $dto, $meta);

        return $s->toArray();
    }

    public function removeSupplier(AdminPrincipal $actor, int $id, RequestMeta $meta): array
    {
        Supplier::whereKey($id)->update(['is_active' => false]);
        $this->audit->log($actor->id, 'supplier.deactivate', 'supplier', $id, null, null, $meta);

        return ['success' => true];
    }
}
