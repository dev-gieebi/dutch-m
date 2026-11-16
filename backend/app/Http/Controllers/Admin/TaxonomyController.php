<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttributeType;
use App\Http\Controllers\Controller;
use App\Services\Catalog\TaxonomyService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Catégories, collections, attributs et fournisseurs. */
class TaxonomyController extends Controller
{
    public function __construct(private readonly TaxonomyService $tax) {}

    private function categoryRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'between:2,120'],
            'slug' => ['sometimes', 'nullable', 'string', 'regex:'.Rules::SLUG_REGEX],
            'code' => [$req, 'string', 'regex:/^[A-Z]{2,6}$/'],
            'parentId' => ['sometimes', 'nullable', 'integer'],
            'description' => ['sometimes', 'nullable', 'string'],
            'imageUrl' => ['sometimes', 'nullable', 'string'],
            'sortOrder' => ['sometimes', 'integer'],
            'isActive' => ['sometimes', 'boolean'],
            'seoTitle' => ['sometimes', 'nullable', 'string', 'max:190'],
            'seoDescription' => ['sometimes', 'nullable', 'string', 'max:320'],
        ];
    }

    private function collectionRules(bool $partial): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'between:2,120'],
            'slug' => ['sometimes', 'nullable', 'string', 'regex:'.Rules::SLUG_REGEX],
            'description' => ['sometimes', 'nullable', 'string'],
            'imageUrl' => ['sometimes', 'nullable', 'string'],
            'startsAt' => ['sometimes', 'nullable', 'date'],
            'endsAt' => ['sometimes', 'nullable', 'date'],
            'isActive' => ['sometimes', 'boolean'],
            'isFeatured' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
            'seoTitle' => ['sometimes', 'nullable', 'string', 'max:190'],
            'seoDescription' => ['sometimes', 'nullable', 'string', 'max:320'],
            'productIds' => ['sometimes', 'array'], 'productIds.*' => ['integer'],
        ];
    }

    private function attributeRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'between:1,80'],
            'code' => [$req, 'string', 'regex:/^[a-z0-9_-]+$/'],
            'type' => [$req, Rule::enum(AttributeType::class)],
            'sortOrder' => ['sometimes', 'integer'],
        ];
    }

    private function valueRules(): array
    {
        return [
            'value' => ['required', 'string', 'between:1,80'],
            'hexColor' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sortOrder' => ['sometimes', 'integer'],
        ];
    }

    private function supplierRules(bool $partial): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'between:2,150'],
            'country' => ['sometimes', 'string', 'max:80'],
            'contactName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'wechat' => ['sometimes', 'nullable', 'string', 'max:80'],
            'email' => ['sometimes', 'nullable', 'email'],
            'usualLeadTimeDays' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    private const MESSAGES = ['code.regex' => 'Code de 2 à 6 lettres majuscules', 'hexColor.regex' => 'Couleur hexadécimale attendue (#RRGGBB)'];

    public function categories(): array
    {
        return $this->tax->listCategories();
    }

    public function createCategory(Request $request): JsonResponse
    {
        return response()->json($this->tax->createCategory($this->admin($request), $request->validate($this->categoryRules(false), self::MESSAGES), $this->meta($request)), 201);
    }

    public function saveTree(Request $request): array
    {
        $v = $request->validate([
            'nodes' => ['present', 'array'],
            'nodes.*.id' => ['required', 'integer'],
            'nodes.*.parentId' => ['nullable', 'integer'],
            'nodes.*.sortOrder' => ['required', 'integer'],
        ]);

        return $this->tax->saveTree($this->admin($request), $v['nodes'], $this->meta($request));
    }

    public function updateCategory(Request $request, int $id): array
    {
        return $this->tax->updateCategory($this->admin($request), $id, $request->validate($this->categoryRules(true), self::MESSAGES), $this->meta($request));
    }

    public function removeCategory(Request $request, int $id): array
    {
        return $this->tax->removeCategory($this->admin($request), $id, $this->meta($request));
    }

    public function collections(): array
    {
        return $this->tax->listCollections();
    }

    public function collection(int $id): array
    {
        return $this->tax->getCollection($id);
    }

    public function createCollection(Request $request): JsonResponse
    {
        return response()->json($this->tax->createCollection($this->admin($request), $request->validate($this->collectionRules(false)), $this->meta($request)), 201);
    }

    public function updateCollection(Request $request, int $id): array
    {
        return $this->tax->updateCollection($this->admin($request), $id, $request->validate($this->collectionRules(true)), $this->meta($request));
    }

    public function removeCollection(Request $request, int $id): array
    {
        return $this->tax->removeCollection($this->admin($request), $id, $this->meta($request));
    }

    public function attributes(): array
    {
        return $this->tax->listAttributes();
    }

    public function createAttribute(Request $request): JsonResponse
    {
        return response()->json($this->tax->createAttribute($request->validate($this->attributeRules(false))), 201);
    }

    public function updateAttribute(Request $request, int $id): array
    {
        return $this->tax->updateAttribute($id, $request->validate($this->attributeRules(true)));
    }

    public function removeAttribute(int $id): array
    {
        return $this->tax->removeAttribute($id);
    }

    public function addValue(Request $request, int $id): JsonResponse
    {
        return response()->json($this->tax->addValue($id, $request->validate($this->valueRules(), self::MESSAGES)), 201);
    }

    public function updateValue(Request $request, int $id): array
    {
        return $this->tax->updateValue($id, $request->validate($this->valueRules(), self::MESSAGES));
    }

    public function removeValue(int $id): array
    {
        return $this->tax->removeValue($id);
    }

    public function suppliers(): array
    {
        return $this->tax->listSuppliers();
    }

    public function supplier(int $id): array
    {
        return $this->tax->getSupplier($id);
    }

    public function createSupplier(Request $request): JsonResponse
    {
        return response()->json($this->tax->createSupplier($this->admin($request), $request->validate($this->supplierRules(false)), $this->meta($request)), 201);
    }

    public function updateSupplier(Request $request, int $id): array
    {
        return $this->tax->updateSupplier($this->admin($request), $id, $request->validate($this->supplierRules(true)), $this->meta($request));
    }

    public function removeSupplier(Request $request, int $id): array
    {
        return $this->tax->removeSupplier($this->admin($request), $id, $this->meta($request));
    }
}
