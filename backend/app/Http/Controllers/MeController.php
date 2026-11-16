<?php

namespace App\Http\Controllers;

use App\Services\CustomersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Espace client « Mon compte » (JWT client obligatoire). */
class MeController extends Controller
{
    public function __construct(private readonly CustomersService $customers) {}

    private static function addressRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:60'],
            'city' => ['required', 'string', 'between:2,80'],
            'zoneId' => ['nullable', 'integer'],
            'district' => ['nullable', 'string', 'max:120'],
            'landmark' => ['required', 'string', 'between:3,255'],
            'instructions' => ['nullable', 'string', 'max:500'],
            'isDefault' => ['nullable', 'boolean'],
        ];
    }

    private const ADDRESS_MESSAGES = ['landmark.required' => 'Le point de repère est obligatoire', 'landmark.between' => 'Le point de repère est obligatoire'];

    public function me(Request $request): array
    {
        return $this->customers->profile($this->customerId($request));
    }

    public function update(Request $request): array
    {
        $v = $request->validate([
            'fullName' => ['sometimes', 'string', 'between:2,120'],
            'email' => ['sometimes', 'nullable', 'email'],
            'newsletterConsent' => ['sometimes', 'boolean'],
            'currentPassword' => ['sometimes', 'nullable', 'string'],
            'newPassword' => ['sometimes', 'nullable', 'string', 'between:8,128'],
        ], ['email.email' => 'Adresse e-mail invalide']);

        return $this->customers->update($this->customerId($request), $v);
    }

    public function remove(Request $request): array
    {
        $v = $request->validate(['password' => ['required', 'string']]);

        return $this->customers->anonymize($this->customerId($request), $v['password']);
    }

    public function orders(Request $request): array
    {
        return $this->customers->myOrders($this->customerId($request));
    }

    public function order(Request $request, string $number): array
    {
        return $this->customers->myOrder($this->customerId($request), $number);
    }

    public function addresses(Request $request): array
    {
        return $this->customers->listAddresses($this->customerId($request));
    }

    public function addAddress(Request $request): JsonResponse
    {
        return response()->json($this->customers->addAddress($this->customerId($request), $request->validate(self::addressRules(), self::ADDRESS_MESSAGES)), 201);
    }

    public function updateAddress(Request $request, int $id): array
    {
        return $this->customers->updateAddress($this->customerId($request), $id, $request->validate(self::addressRules(), self::ADDRESS_MESSAGES));
    }

    public function removeAddress(Request $request, int $id): array
    {
        return $this->customers->removeAddress($this->customerId($request), $id);
    }

    public function wishlist(Request $request): array
    {
        return $this->customers->wishlist($this->customerId($request));
    }

    public function addWishlist(Request $request): JsonResponse
    {
        $v = $request->validate(['productId' => ['required', 'integer']]);

        return response()->json($this->customers->addWishlist($this->customerId($request), $v['productId']), 201);
    }

    public function removeWishlist(Request $request, int $productId): array
    {
        return $this->customers->removeWishlist($this->customerId($request), $productId);
    }

    public function cart(Request $request): array
    {
        return $this->customers->getCart($this->customerId($request));
    }

    public function putCart(Request $request): array
    {
        $v = $request->validate([
            'items' => ['present', 'array', 'max:100'],
            'items.*.variantId' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->customers->putCart($this->customerId($request), $v['items']);
    }
}
