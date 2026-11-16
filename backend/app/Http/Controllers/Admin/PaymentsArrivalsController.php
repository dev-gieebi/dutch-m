<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\ShipmentStep;
use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Services\Arrivals\ArrivalsService;
use App\Services\AuditService;
use App\Services\Payments\PaymentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Back-office : paiements, précommandes / arrivages et zones de livraison. */
class PaymentsArrivalsController extends Controller
{
    public function __construct(
        private readonly PaymentsService $payments,
        private readonly ArrivalsService $arrivals,
        private readonly AuditService $audit,
    ) {}

    // ----- Paiements -------------------------------------------------------------

    public function payments(Request $request): array
    {
        $v = $request->validate([
            'provider' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(PaymentRecordStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string'],
            'duplicates' => ['nullable', 'in:true,false,1,0'],
            'orderId' => ['nullable', 'integer'],
        ]);
        $v['duplicates'] = in_array($v['duplicates'] ?? null, ['true', '1'], true);

        return $this->payments->list($v + $this->pagination($request));
    }

    public function reconciliation(Request $request): array
    {
        return $this->payments->reconciliation($request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]));
    }

    public function manual(Request $request, int $id): JsonResponse
    {
        $actor = $this->admin($request);
        if (! $actor->isSuper()) {
            throw new HttpException(403, "Vous n'avez pas le rôle requis pour cette action");
        }
        $v = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'between:4,120'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['reference.required' => 'La référence de transaction est obligatoire', 'reference.between' => 'La référence de transaction est obligatoire']);

        return response()->json($this->payments->manual($id, $v, $actor, $this->meta($request)), 201);
    }

    public function refund(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'between:3,500'],
            'paymentId' => ['nullable', 'integer'],
            'manual' => ['nullable', 'boolean'],
            'manualReference' => ['nullable', 'string', 'max:120'],
        ], ['reason.required' => 'Le motif du remboursement est obligatoire', 'reason.between' => 'Le motif du remboursement est obligatoire']);

        return response()->json($this->payments->refund($id, $v, $this->admin($request), $this->meta($request)), 201);
    }

    // ----- Précommandes et arrivages ----------------------------------------------

    private static function infoRules(): array
    {
        return [
            'supplierId' => ['sometimes', 'nullable', 'integer'],
            'carrier' => ['sometimes', 'nullable', 'string', 'max:120'],
            'carrierTrackingNumber' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shippedAt' => ['sometimes', 'nullable', 'date'],
            'estimatedArrivalAt' => ['sometimes', 'nullable', 'date'],
            'transportCost' => ['sometimes', 'integer', 'min:0'],
            'customsCost' => ['sometimes', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function pendingPreorders(): array
    {
        return $this->arrivals->pendingLines();
    }

    public function arrivalsList(Request $request): array
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'step' => ['nullable', Rule::enum(ShipmentStep::class)],
            'supplierId' => ['nullable', 'integer'],
        ]);

        return $this->arrivals->list($v + $this->pagination($request));
    }

    public function arrival(int $id): array
    {
        return $this->arrivals->detail($id);
    }

    public function createArrival(Request $request): JsonResponse
    {
        $v = $request->validate(self::infoRules() + [
            'itemIds' => ['required', 'array', 'min:1'], 'itemIds.*' => ['integer'],
            'publicComment' => ['nullable', 'string', 'max:1000'],
        ], ['itemIds.required' => 'Sélectionnez au moins une ligne', 'itemIds.min' => 'Sélectionnez au moins une ligne']);

        return response()->json($this->arrivals->create($v, $this->admin($request), $this->meta($request)), 201);
    }

    public function updateArrival(Request $request, int $id): array
    {
        return $this->arrivals->update($id, $request->validate(self::infoRules()), $this->admin($request), $this->meta($request));
    }

    private static function itemsRules(): array
    {
        return ['itemIds' => ['required', 'array', 'min:1'], 'itemIds.*' => ['integer']];
    }

    public function addItems(Request $request, int $id): JsonResponse
    {
        return response()->json($this->arrivals->addItems($id, $request->validate(self::itemsRules()), $this->admin($request), $this->meta($request)), 201);
    }

    public function removeItems(Request $request, int $id): JsonResponse
    {
        return response()->json($this->arrivals->removeItems($id, $request->validate(self::itemsRules()), $this->admin($request), $this->meta($request)), 201);
    }

    public function arrivalStep(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'step' => ['required', Rule::enum(ShipmentStep::class)],
            'publicComment' => ['nullable', 'string', 'max:1000'],
            'internalNote' => ['nullable', 'string', 'max:2000'],
            'force' => ['nullable', 'boolean'],
            'justification' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->arrivals->setStep($id, $v, $this->admin($request), $this->meta($request)), 201);
    }

    public function reception(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'lines' => ['present', 'array'],
            'lines.*.itemId' => ['required', 'integer'],
            'lines.*.receivedQty' => ['required', 'integer', 'min:0'],
            'publicComment' => ['nullable', 'string', 'max:1000'],
            'internalNote' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->arrivals->receive($id, $v, $this->admin($request), $this->meta($request)), 201);
    }

    public function addDocument(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'url' => ['required', 'string', 'max:500'],
            'mimeType' => ['required', 'string', 'max:80'],
        ]);

        return response()->json($this->arrivals->addDocument($id, $v), 201);
    }

    public function removeDocument(int $docId): array
    {
        return $this->arrivals->removeDocument($docId);
    }

    // ----- Zones de livraison -------------------------------------------------------

    public function publicZones(): JsonResponse
    {
        $zones = DeliveryZone::where('is_active', true)->orderBy('city')->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn ($z) => ['id' => $z->id, 'city' => $z->city, 'name' => $z->name, 'fee' => $z->fee, 'estimatedDelay' => $z->estimated_delay]);

        return response()->json($zones)->header('Cache-Control', 'public, max-age=300');
    }

    public function zones(): array
    {
        return DeliveryZone::orderBy('city')->orderBy('sort_order')->orderBy('name')->get()->toArray();
    }

    private static function zoneRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'city' => [$req, 'string', 'between:2,80'],
            'name' => [$req, 'string', 'between:2,120'],
            'fee' => [$req, 'integer', 'min:0'],
            'estimatedDelay' => ['sometimes', 'string', 'between:1,60'],
            'estimatedDays' => ['sometimes', 'integer', 'between:0,60'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
        ];
    }

    private static function fillZone(DeliveryZone $z, array $dto): void
    {
        foreach ($dto as $k => $v) {
            $z->setAttribute(Str::snake($k), $v);
        }
    }

    public function createZone(Request $request): JsonResponse
    {
        $zone = new DeliveryZone;
        self::fillZone($zone, $request->validate(self::zoneRules(false)));
        $zone->save();
        $zone->refresh();
        $this->audit->log($this->admin($request)->id, 'zone.create', 'delivery_zone', $zone->id, null, $zone, $this->meta($request));

        return response()->json($zone->toArray(), 201);
    }

    public function updateZone(Request $request, int $id): array
    {
        $zone = DeliveryZone::find($id) ?? throw new HttpException(404, 'Zone introuvable');
        $before = $zone->attributesToArrayCamel();
        self::fillZone($zone, $request->validate(self::zoneRules(true)));
        $zone->save();
        $this->audit->log($this->admin($request)->id, 'zone.update', 'delivery_zone', $id, $before, $zone, $this->meta($request));

        return $zone->toArray();
    }

    public function removeZone(Request $request, int $id): array
    {
        DeliveryZone::whereKey($id)->update(['is_active' => false]);
        $this->audit->log($this->admin($request)->id, 'zone.deactivate', 'delivery_zone', $id, null, null, $this->meta($request));

        return ['success' => true];
    }
}
