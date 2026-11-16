<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AvailabilityMode;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Services\Catalog\ProductsAdminService;
use App\Support\Csv;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductsController extends Controller
{
    public function __construct(private readonly ProductsAdminService $products) {}

    private function productRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'between:2,190'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:190', 'regex:'.Rules::SLUG_REGEX],
            'reference' => ['sometimes', 'nullable', 'string', 'max:40'],
            'categoryId' => ['sometimes', 'nullable', 'integer'],
            'collectionIds' => ['sometimes', 'array'], 'collectionIds.*' => ['integer'],
            'shortDescription' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string'],
            'dimensions' => ['sometimes', 'nullable', 'string', 'max:190'],
            'material' => ['sometimes', 'nullable', 'string', 'max:120'],
            'colors' => ['sometimes', 'array', 'max:20'], 'colors.*' => ['string'],
            'careInstructions' => ['sometimes', 'nullable', 'string'],
            'weight' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'basePrice' => [$req, 'integer', 'min:0'],
            'compareAtPrice' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'costPrice' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'availabilityMode' => [$req, Rule::enum(AvailabilityMode::class)],
            'leadTimeDays' => ['sometimes', 'integer', 'between:1,365'],
            'depositPercent' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'fullPaymentRequired' => ['sometimes', 'boolean'],
            'autoPreorderWhenOutOfStock' => ['sometimes', 'boolean'],
            'maxPreorderQty' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'supplierId' => ['sometimes', 'nullable', 'integer'],
            'relatedProductIds' => ['sometimes', 'array'], 'relatedProductIds.*' => ['integer'],
            'seoTitle' => ['sometimes', 'nullable', 'string', 'max:190'],
            'seoDescription' => ['sometimes', 'nullable', 'string', 'max:320'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'isFeatured' => ['sometimes', 'boolean'],
            'isNew' => ['sometimes', 'boolean'],
            'isBestSeller' => ['sometimes', 'boolean'],
            'initialStock' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function variantRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'sku' => ['sometimes', 'nullable', 'string', 'max:60'],
            'name' => [$req, 'string', 'between:1,150'],
            'price' => [$req, 'integer', 'min:0'],
            'compareAtPrice' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'promoPrice' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'promoStartsAt' => ['sometimes', 'nullable', 'date'],
            'promoEndsAt' => ['sometimes', 'nullable', 'date'],
            'costPrice' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lowStockThreshold' => ['sometimes', 'integer', 'min:0'],
            'availabilityMode' => ['sometimes', 'nullable', Rule::enum(AvailabilityMode::class)],
            'isDefault' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
            'attributeValueIds' => ['sometimes', 'array'], 'attributeValueIds.*' => ['integer'],
            'initialStock' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function list(Request $request): array
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'categoryId' => ['nullable', 'integer'],
            'availability' => ['nullable', Rule::enum(AvailabilityMode::class)],
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'lowStock' => ['nullable', 'in:true,false,1,0'],
        ]);
        $v['lowStock'] = in_array($v['lowStock'] ?? null, ['true', '1'], true);

        return $this->products->list($v + $this->pagination($request));
    }

    public function exportCsv(): Response
    {
        return Csv::download($this->products->exportCsv(), 'produits-amoria.csv');
    }

    public function importCsv(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file) {
            throw new HttpException(400, 'Fichier CSV manquant');
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new HttpException(413, 'Fichier trop volumineux');
        }

        return response()->json($this->products->importCsv($this->admin($request), file_get_contents($file->getRealPath()), $this->meta($request)), 201);
    }

    public function bulk(Request $request): JsonResponse
    {
        $v = $request->validate([
            'ids' => ['present', 'array'], 'ids.*' => ['integer'],
            'action' => ['required', 'in:publish,unpublish,setCategory,applyPromotion,clearPromotion,delete'],
            'categoryId' => ['nullable', 'integer'],
            'percent' => ['nullable', 'integer', 'between:1,90'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date'],
        ]);

        return response()->json($this->products->bulk($this->admin($request), $v, $this->meta($request)), 201);
    }

    public function get(int $id): array
    {
        return $this->products->get($id);
    }

    public function create(Request $request): JsonResponse
    {
        $v = $request->validate($this->productRules(false), ['slug.regex' => 'Slug invalide']);

        return response()->json($this->products->create($this->admin($request), $v, $this->meta($request)), 201);
    }

    public function update(Request $request, int $id): array
    {
        $v = $request->validate($this->productRules(true), ['slug.regex' => 'Slug invalide']);

        return $this->products->update($this->admin($request), $id, $v, $this->meta($request));
    }

    public function remove(Request $request, int $id): array
    {
        return $this->products->remove($this->admin($request), $id, $this->meta($request));
    }

    public function createVariant(Request $request, int $id): JsonResponse
    {
        $v = $request->validate($this->variantRules(false));

        return response()->json($this->products->createVariant($this->admin($request), $id, $v, $this->meta($request)), 201);
    }

    public function generate(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'attributeValueIds' => ['required', 'array'], 'attributeValueIds.*' => ['integer'],
            'price' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($this->products->generateVariants($this->admin($request), $id, $v, $this->meta($request)), 201);
    }

    public function updateVariant(Request $request, int $variantId): array
    {
        $v = $request->validate($this->variantRules(true));

        return $this->products->updateVariant($this->admin($request), $variantId, $v, $this->meta($request));
    }

    public function removeVariant(Request $request, int $variantId): array
    {
        return $this->products->removeVariant($this->admin($request), $variantId, $this->meta($request));
    }

    public function addImage(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'mediumUrl' => ['required', 'string', 'max:500'],
            'thumbUrl' => ['required', 'string', 'max:500'],
            'alt' => ['nullable', 'string', 'max:255'],
            'variantId' => ['nullable', 'integer'],
            'isMain' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->products->addImage($id, $v), 201);
    }

    public function reorder(Request $request, int $id): array
    {
        $v = $request->validate(['ids' => ['present', 'array'], 'ids.*' => ['integer']]);

        return $this->products->reorderImages($id, $v['ids']);
    }

    public function updateImage(Request $request, int $imageId): array
    {
        $v = $request->validate([
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'variantId' => ['sometimes', 'nullable', 'integer'],
            'isMain' => ['sometimes', 'boolean'],
        ]);

        return $this->products->updateImage($imageId, $v);
    }

    public function removeImage(int $imageId): array
    {
        return $this->products->removeImage($imageId);
    }
}
