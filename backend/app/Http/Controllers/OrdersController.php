<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryGrouping;
use App\Enums\DeliveryMode;
use App\Enums\OrderSource;
use App\Enums\PaymentMethod;
use App\Services\Orders\CartService;
use App\Services\Orders\OrdersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Panier, création de commande et suivi public. */
class OrdersController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly OrdersService $orders,
    ) {}

    public static function itemRules(string $prefix = 'items'): array
    {
        return [
            "{$prefix}.*.variantId" => ['required', 'integer', 'min:1'],
            "{$prefix}.*.quantity" => ['required', 'integer', 'between:1,999'],
            "{$prefix}.*.expectedPrice" => ['nullable', 'integer'],
        ];
    }

    /** Règles du tunnel de commande (partagées avec la création admin). */
    public static function createRules(): array
    {
        return [
            'contact' => ['required', 'array'],
            'contact.fullName' => ['required', 'string', 'between:2,120'],
            'contact.phone' => ['required', 'string', 'max:32'],
            'contact.email' => ['nullable', 'email'],
            'contact.createAccount' => ['nullable', 'boolean'],
            'contact.password' => ['nullable', 'string', 'between:8,128'],
            'delivery' => ['required', 'array'],
            'delivery.mode' => ['required', Rule::enum(DeliveryMode::class)],
            'delivery.zoneId' => ['nullable', 'integer'],
            'delivery.city' => ['nullable', 'string', 'max:80'],
            'delivery.district' => ['nullable', 'string', 'max:120'],
            'delivery.landmark' => ['nullable', 'string', 'max:255'],
            'delivery.instructions' => ['nullable', 'string', 'max:500'],
            'delivery.grouping' => ['nullable', Rule::enum(DeliveryGrouping::class)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            ...self::itemRules(),
            'couponCode' => ['nullable', 'string', 'max:40'],
            'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'preorderTermsAccepted' => ['nullable', 'boolean'],
            'cgvAccepted' => ['required', 'boolean'],
        ];
    }

    public const CREATE_MESSAGES = [
        'contact.fullName.between' => 'Indiquez votre nom complet',
        'contact.fullName.required' => 'Indiquez votre nom complet',
        'contact.email.email' => 'Adresse e-mail invalide',
        'contact.password.between' => 'Mot de passe de 8 caractères minimum',
        'items.required' => 'Votre panier est vide',
        'items.min' => 'Votre panier est vide',
    ];

    public function validateCart(Request $request): array
    {
        $v = $request->validate([
            'items' => ['present', 'array', 'max:100'],
            ...self::itemRules(),
            'couponCode' => ['nullable', 'string', 'max:40'],
            'zoneId' => ['nullable', 'integer'],
            'deliveryMode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'grouping' => ['nullable', Rule::enum(DeliveryGrouping::class)],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        return $this->cart->validate($v + ['customerId' => $this->customerId($request)])['result'];
    }

    public function create(Request $request): JsonResponse
    {
        $v = $request->validate(self::createRules(), self::CREATE_MESSAGES);

        return response()->json($this->orders->create($v, [
            'source' => OrderSource::WEB->value,
            'customerId' => $this->customerId($request),
            'ip' => $request->ip(),
        ]), 201);
    }

    public function track(Request $request): array
    {
        $v = $request->validate([
            'number' => ['required', 'string', 'regex:/^AMO-\d{4}-\d{6}$/i'],
            'phone' => ['required', 'string', 'max:32'],
        ], ['number.regex' => 'Format attendu : AMO-2026-000123']);

        return $this->orders->trackByNumber($v['number'], $v['phone']);
    }

    public function trackByToken(string $token): array
    {
        return $this->orders->trackByToken($token);
    }
}
