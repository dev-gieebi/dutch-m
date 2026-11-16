<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function overview(Request $request): array
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'categoryId' => ['nullable', 'integer'],
            'lowStock' => ['nullable', 'in:true,false,1,0'],
        ]);
        $v['lowStock'] = in_array($v['lowStock'] ?? null, ['true', '1'], true);

        return $this->inventory->overview($v + $this->pagination($request));
    }

    public function movements(Request $request): array
    {
        $v = $request->validate([
            'variantId' => ['nullable', 'integer'],
            'orderId' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(StockMovementType::class)],
            'q' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $this->inventory->movementsList($v + $this->pagination($request));
    }

    public function alerts(): array
    {
        return $this->inventory->alertsSummary();
    }

    public function sendAlerts(int $variantId): JsonResponse
    {
        return response()->json($this->inventory->sendAlerts($variantId), 201);
    }

    public function adjust(Request $request, int $variantId): JsonResponse
    {
        $v = $request->validate([
            'mode' => ['required', 'in:delta,set'],
            'value' => ['required', 'integer'],
            'reason' => ['required', 'string', 'between:3,255'],
            'type' => ['nullable', 'in:IN,ADJUST'],
        ], ['reason.required' => 'Le motif est obligatoire', 'reason.between' => 'Le motif est obligatoire']);

        return response()->json($this->inventory->adjust($variantId, $v, $this->admin($request)->id, $this->meta($request)), 201);
    }
}
