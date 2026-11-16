<?php

namespace App\Http\Controllers;

use App\Services\Catalog\CatalogPublicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Catalogue public de la boutique. */
class CatalogController extends Controller
{
    public function __construct(private readonly CatalogPublicService $catalog) {}

    private function cached(mixed $data, int $maxAge): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', "public, max-age={$maxAge}");
    }

    public static function productQueryRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'category' => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:120'],
            'minPrice' => ['nullable', 'integer', 'min:0'],
            'maxPrice' => ['nullable', 'integer', 'min:0'],
            'color' => ['nullable', 'string'],
            'material' => ['nullable', 'string'],
            'availability' => ['nullable', 'in:IN_STOCK,PREORDER'],
            'onSale' => ['nullable', 'in:true,false,1,0'],
            'isNew' => ['nullable', 'in:true,false,1,0'],
            'bestSeller' => ['nullable', 'in:true,false,1,0'],
            'featured' => ['nullable', 'in:true,false,1,0'],
            'sort' => ['nullable', 'in:relevance,newest,price_asc,price_desc,best_sellers'],
        ];
    }

    private function productQuery(Request $request): array
    {
        $v = $request->validate(self::productQueryRules());
        foreach (['onSale', 'isNew', 'bestSeller', 'featured'] as $b) {
            $v[$b] = in_array($v[$b] ?? null, ['true', '1'], true);
        }

        return $v + ['page' => 1, 'limit' => 20];
    }

    public function categories(): JsonResponse
    {
        return $this->cached($this->catalog->categoryTree(), 300);
    }

    public function category(string $slug): JsonResponse
    {
        return $this->cached($this->catalog->categoryBySlug($slug), 300);
    }

    public function collections(): JsonResponse
    {
        return $this->cached($this->catalog->listCollections(), 300);
    }

    public function collection(string $slug): JsonResponse
    {
        return $this->cached($this->catalog->collectionBySlug($slug), 300);
    }

    public function products(Request $request): JsonResponse
    {
        return $this->cached($this->catalog->listProducts($this->productQuery($request)), 60);
    }

    public function facets(Request $request): JsonResponse
    {
        return $this->cached($this->catalog->facets($this->productQuery($request)), 300);
    }

    public function product(string $slug): JsonResponse
    {
        return $this->cached($this->catalog->productBySlug($slug), 30);
    }

    public function related(string $slug): JsonResponse
    {
        return $this->cached($this->catalog->related($slug), 300);
    }

    public function reviews(string $slug): JsonResponse
    {
        return $this->cached($this->catalog->productReviews($slug), 300);
    }

    public function highlights(): JsonResponse
    {
        return $this->cached($this->catalog->homeReviews(), 600);
    }

    public function search(Request $request): array
    {
        return $this->catalog->search($request->query('q'));
    }

    public function backInStock(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
        ], ['email.email' => 'Adresse e-mail invalide']);

        return response()->json($this->catalog->subscribeBackInStock($id, $v), 201);
    }
}
