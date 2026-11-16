<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationTemplateCode;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShipmentStep;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OrdersController as PublicOrders;
use App\Services\Orders\OrdersAdminService;
use App\Services\Orders\OrdersService;
use App\Services\Orders\TrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Commandes, expéditions, planning et espace livreur. */
class OrdersController extends Controller
{
    public function __construct(
        private readonly OrdersAdminService $admin,
        private readonly OrdersService $orders,
        private readonly TrackingService $tracking,
    ) {}

    public function list(Request $request): array
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'paymentStatus' => ['nullable', Rule::enum(PaymentStatus::class)],
            'paymentMethod' => ['nullable', Rule::enum(PaymentMethod::class)],
            'orderType' => ['nullable', 'in:LOCAL,PREORDER,MIXED'],
            'zoneId' => ['nullable', 'integer'],
            'driverId' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'preset' => ['nullable', 'in:todo,delayed,awaiting_cash,pending_payment'],
        ]);

        return $this->admin->list($v + $this->pagination($request));
    }

    public function create(Request $request): JsonResponse
    {
        $v = $request->validate(PublicOrders::createRules() + [
            'source' => ['required', 'in:ADMIN,PHONE,WHATSAPP'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], PublicOrders::CREATE_MESSAGES);
        $a = $this->admin($request);

        return response()->json($this->orders->create($v, ['source' => $v['source'], 'adminUserId' => $a->id, 'ip' => $request->ip(), 'note' => $v['note'] ?? null]), 201);
    }

    public function detail(int $id): array
    {
        return $this->admin->detail($id);
    }

    public function print(Request $request, int $id): Response
    {
        return response($this->admin->printable($id, $request->query('kind') === 'delivery' ? 'delivery' : 'order'), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        return response()->json($this->admin->confirm($id, $this->admin($request), $this->meta($request)), 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'between:3,500']], [
            'reason.required' => "Le motif d'annulation est obligatoire", 'reason.between' => "Le motif d'annulation est obligatoire",
        ]);

        return response()->json($this->admin->cancel($id, $v['reason'], $this->admin($request), $this->meta($request)), 201);
    }

    public function note(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['note' => ['required', 'string', 'between:1,2000']]);

        return response()->json($this->admin->addNote($id, $v['note'], $this->admin($request), $this->meta($request)), 201);
    }

    public function resend(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['template' => ['required', Rule::enum(NotificationTemplateCode::class)]]);

        return response()->json($this->admin->resendNotification($id, $v['template'], $this->admin($request), $this->meta($request)), 201);
    }

    private static function cashRules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:CASH,AIRTEL_MONEY,MOOV_MONEY'],
            'reference' => ['nullable', 'string', 'max:120'],
            'shipmentId' => ['nullable', 'integer'],
        ];
    }

    public function cash(Request $request, int $id): JsonResponse
    {
        return response()->json($this->tracking->collectCash($id, $request->validate(self::cashRules()), $this->admin($request), $this->meta($request)), 201);
    }

    public function step(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'step' => ['required', Rule::enum(ShipmentStep::class)],
            'publicComment' => ['nullable', 'string', 'max:1000'],
            'internalNote' => ['nullable', 'string', 'max:2000'],
            'proofUrl' => ['nullable', 'string', 'max:500'],
            'force' => ['nullable', 'boolean'],
            'justification' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->tracking->recordStep($id, $v, $this->admin($request), $this->meta($request)), 201);
    }

    public function delay(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'newEstimatedDate' => ['required', 'date'],
            'reason' => ['required', 'string', 'between:3,500'],
        ], ['reason.required' => 'Le motif du retard est obligatoire', 'reason.between' => 'Le motif du retard est obligatoire']);

        return response()->json($this->tracking->delay($id, $v, $this->admin($request), $this->meta($request)), 201);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['driverId' => ['nullable', 'integer'], 'scheduledFor' => ['nullable', 'date']]);

        return response()->json($this->tracking->assignDriver($id, $v, $this->admin($request), $this->meta($request)), 201);
    }

    public function regenerate(Request $request, int $id): JsonResponse
    {
        return response()->json($this->tracking->regenerateCode($id, $this->admin($request), $this->meta($request)), 201);
    }

    public function planning(Request $request): array
    {
        $v = $request->validate(['date' => ['nullable', 'date']]);

        return $this->tracking->planning($v['date'] ?? null);
    }

    public function driverDeliveries(Request $request): array
    {
        $v = $request->validate(['date' => ['nullable', 'date']]);

        return $this->tracking->driverDeliveries($this->admin($request), $v['date'] ?? null);
    }

    public function completeDelivery(Request $request, int $shipmentId): JsonResponse
    {
        $v = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{4}$/'],
            'proofUrl' => ['nullable', 'string', 'max:500'],
            'cashAmount' => ['nullable', 'integer', 'min:0'],
            'cashMethod' => ['nullable', 'in:CASH,AIRTEL_MONEY,MOOV_MONEY'],
            'cashReference' => ['nullable', 'string', 'max:120'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ], ['code.regex' => 'Le code comporte 4 chiffres']);

        return response()->json($this->tracking->completeDelivery($shipmentId, $v, $this->admin($request), $this->meta($request)), 201);
    }
}
