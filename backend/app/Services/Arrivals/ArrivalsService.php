<?php

namespace App\Services\Arrivals;

use App\Enums\AdminNotificationType;
use App\Enums\ItemType;
use App\Enums\ShipmentStep;
use App\Events\DomainEvent;
use App\Models\AdminNotification;
use App\Models\Arrival;
use App\Models\ArrivalDocument;
use App\Models\ArrivalEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Services\AuditService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockEffects;
use App\Services\NumberingService;
use App\Services\Orders\TrackingService;
use App\Support\AdminPrincipal;
use App\Support\Domain;
use App\Support\Format;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Précommandes et arrivages fournisseur.
 * Le suivi transporteur est saisi manuellement (« carrierProvider » = manual) ; brancher ici une API transporteur si besoin.
 */
class ArrivalsService
{
    private const ELIGIBLE_ORDER_STATUSES = ['CONFIRMED', 'IN_PROGRESS', 'PARTIALLY_DELIVERED'];

    private const ELIGIBLE_PAYMENT = ['DEPOSIT_PAID', 'PAID', 'AWAITING_CASH'];

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly TrackingService $tracking,
        private readonly InventoryService $inventory,
        private readonly AuditService $audit,
    ) {}

    /** Lignes de précommande confirmées (acompte payé) non rattachées à un arrivage. */
    public function pendingLines(): array
    {
        $rows = OrderItem::query()->select('order_items.*')
            ->join('orders as o', 'o.id', '=', 'order_items.order_id')
            ->with(['order', 'variant.product' => fn ($q) => $q->withTrashed()->with('supplier')])
            ->where('order_items.item_type', ItemType::PREORDER->value)->whereNull('order_items.arrival_id')
            ->whereIn('o.status', self::ELIGIBLE_ORDER_STATUSES)->whereIn('o.payment_status', self::ELIGIBLE_PAYMENT)
            ->orderBy('o.created_at')->get();
        $lines = $rows->map(fn ($i) => [
            'itemId' => $i->id,
            'orderId' => $i->order_id,
            'orderNumber' => $i->order->number,
            'orderDate' => Format::iso($i->order->created_at),
            'customerName' => $i->order->contact_name,
            'productId' => $i->product_id,
            'productName' => $i->product_name,
            'reference' => $i->reference,
            'variantName' => $i->variant_name,
            'quantity' => $i->quantity,
            'supplierId' => $i->variant?->product?->supplier_id,
            'supplierName' => $i->variant?->product?->supplier?->name ?? 'Sans fournisseur',
        ])->all();
        $groups = [];
        foreach ($lines as $l) {
            $key = ($l['supplierId'] ?? 0)."|{$l['reference']}";
            $groups[$key] ??= ['supplierId' => $l['supplierId'], 'supplierName' => $l['supplierName'], 'productName' => $l['productName'],
                'reference' => $l['reference'], 'variantName' => $l['variantName'], 'totalQuantity' => 0, 'itemIds' => []];
            $groups[$key]['totalQuantity'] += $l['quantity'];
            $groups[$key]['itemIds'][] = $l['itemId'];
        }

        return ['lines' => $lines, 'groups' => array_values($groups)];
    }

    public function list(array $q): array
    {
        $qb = Arrival::with('supplier')->withCount('items');
        if (! empty($q['q'])) {
            $like = "%{$q['q']}%";
            $qb->where(fn ($w) => $w->where('reference', 'like', $like)->orWhere('carrier_tracking_number', 'like', $like)->orWhere('carrier', 'like', $like));
        }
        if (! empty($q['step'])) {
            $qb->where('current_step', $q['step']);
        }
        if (! empty($q['supplierId'])) {
            $qb->where('supplier_id', $q['supplierId']);
        }
        $total = (clone $qb)->count();
        $rows = $qb->orderByDesc('created_at')->forPage($q['page'], $q['limit'])->get();

        return Paginated::make($rows->map(function ($a) {
            $arr = $a->toArray();
            unset($arr['itemsCount']);

            return $arr + ['itemCount' => (int) $a->items_count];
        })->all(), $total, $q);
    }

    public function detail(int $id): array
    {
        $a = Arrival::with(['supplier', 'items.order', 'events.adminUser', 'documents'])->find($id) ?? throw new HttpException(404, 'Arrivage introuvable');
        $orders = [];
        foreach ($a->items as $i) {
            $orders[$i->order_id] = ['id' => $i->order->id, 'number' => $i->order->number, 'customerName' => $i->order->contact_name, 'status' => $i->order->status];
        }

        return $a->attributesToArrayCamel() + [
            'supplier' => $a->supplier?->toArray(),
            'documents' => $a->documents->toArray(),
            'items' => $a->items->map(fn ($i) => $i->attributesToArrayCamel() + ['order' => ['id' => $i->order->id, 'number' => $i->order->number, 'contactName' => $i->order->contact_name]])->all(),
            'orders' => array_values($orders),
            'events' => $a->events->sortBy(fn ($e) => $e->occurred_at->valueOf())->values()->map(fn ($e) => $e->attributesToArrayCamel() + [
                'label' => Domain::STEP_LABELS[$e->step],
                'adminUser' => $e->adminUser ? ['id' => $e->adminUser->id, 'fullName' => $e->adminUser->full_name] : null,
            ])->all(),
            'steps' => array_map(fn ($s) => ['step' => $s, 'label' => Domain::STEP_LABELS[$s]], Domain::ARRIVAL_STEPS),
            'carrierProvider' => 'manual',
        ];
    }

    private function applyInfo(Arrival $a, array $dto): void
    {
        foreach (['supplierId', 'carrier', 'carrierTrackingNumber', 'transportCost', 'customsCost', 'notes'] as $k) {
            if (array_key_exists($k, $dto)) {
                $a->setAttribute(Str::snake($k), $dto[$k]);
            }
        }
        foreach (['shippedAt' => 'shipped_at', 'estimatedArrivalAt' => 'estimated_arrival_at'] as $k => $col) {
            if (array_key_exists($k, $dto)) {
                $a->{$col} = $dto[$k] ? Carbon::parse($dto[$k]) : null;
            }
        }
    }

    private function lockEligibleItems(array $itemIds)
    {
        $items = OrderItem::whereIn('id', $itemIds)->lockForUpdate()->get();
        if ($items->count() !== count(array_unique($itemIds))) {
            throw new HttpException(400, 'Certaines lignes sont introuvables');
        }
        if ($items->contains(fn ($i) => $i->item_type !== ItemType::PREORDER->value)) {
            throw new HttpException(400, 'Seules les lignes de précommande peuvent être rattachées');
        }
        if ($items->contains(fn ($i) => $i->arrival_id)) {
            throw new HttpException(400, 'Certaines lignes sont déjà rattachées à un arrivage');
        }
        if (Order::whereIn('id', $items->pluck('order_id')->unique())->whereNotIn('status', self::ELIGIBLE_ORDER_STATUSES)->exists()) {
            throw new HttpException(400, 'Certaines commandes ne sont pas confirmées');
        }

        return $items;
    }

    private function emitAll(array $notify): void
    {
        foreach ($notify as $e) {
            DomainEvent::emit(DomainEvent::SHIPMENT_STEP, $e);
        }
    }

    public function create(array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $effects = new StockEffects;
        [$saved, $notify] = DB::transaction(function () use ($dto, $actor, $effects) {
            $items = $this->lockEligibleItems($dto['itemIds']);
            $a = new Arrival(['reference' => $this->numbering->arrivalReference(), 'current_step' => ShipmentStep::ORDERED_FROM_SUPPLIER->value]);
            $this->applyInfo($a, $dto);
            $a->save();
            OrderItem::whereIn('id', $items->pluck('id'))->update(['arrival_id' => $a->id]);
            ArrivalEvent::create([
                'arrival_id' => $a->id,
                'step' => ShipmentStep::ORDERED_FROM_SUPPLIER->value,
                'public_comment' => $dto['publicComment'] ?? 'Vos articles ont été commandés auprès de notre fournisseur.',
                'admin_user_id' => $actor->id,
                'occurred_at' => now(),
            ]);
            $notify = $this->propagate($a, $items->pluck('shipment_id')->filter()->all(), $dto['publicComment'] ?? null, null, $actor->id, $effects);

            return [$a, $notify];
        });
        $this->inventory->flush($effects);
        $this->emitAll($notify);
        $this->audit->log($actor->id, 'arrival.create', 'arrival', $saved->id, null, $dto, $meta);

        return $this->detail($saved->id);
    }

    public function update(int $id, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $a = Arrival::find($id) ?? throw new HttpException(404, 'Arrivage introuvable');
        $before = $a->attributesToArrayCamel();
        $this->applyInfo($a, $dto);
        $a->save();
        $this->audit->log($actor->id, 'arrival.update', 'arrival', $id, $before, $dto, $meta);

        return $this->detail($id);
    }

    public function addItems(int $id, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $effects = new StockEffects;
        $notify = DB::transaction(function () use ($id, $dto, $actor, $effects) {
            $a = Arrival::find($id) ?? throw new HttpException(404, 'Arrivage introuvable');
            if ($a->current_step === ShipmentStep::RECEIVED_IN_STOCK->value) {
                throw new HttpException(400, 'Arrivage déjà réceptionné');
            }
            $items = $this->lockEligibleItems($dto['itemIds']);
            OrderItem::whereIn('id', $items->pluck('id'))->update(['arrival_id' => $id]);

            return $this->propagate($a, $items->pluck('shipment_id')->filter()->all(), null, null, $actor->id, $effects);
        });
        $this->inventory->flush($effects);
        $this->emitAll($notify);
        $this->audit->log($actor->id, 'arrival.add_items', 'arrival', $id, null, $dto, $meta);

        return $this->detail($id);
    }

    public function removeItems(int $id, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $a = Arrival::find($id) ?? throw new HttpException(404, 'Arrivage introuvable');
        if ($a->current_step !== ShipmentStep::ORDERED_FROM_SUPPLIER->value) {
            throw new HttpException(400, "Les lignes ne peuvent être retirées qu'avant l'expédition");
        }
        OrderItem::whereIn('id', $dto['itemIds'])->where('arrival_id', $id)->update(['arrival_id' => null]);
        $this->audit->log($actor->id, 'arrival.remove_items', 'arrival', $id, null, $dto, $meta);

        return $this->detail($id);
    }

    /**
     * Propage l'état des arrivages aux expéditions précommande liées (règle 6.6).
     * Renvoie la liste des notifications client à émettre (une par expédition, sur l'étape finale).
     */
    public function propagate(Arrival $arrival, array $shipmentIds, ?string $publicComment, ?string $internalNote, int $adminUserId, StockEffects $effects): array
    {
        $ids = $shipmentIds ?: OrderItem::where('arrival_id', $arrival->id)->whereNotNull('shipment_id')->pluck('shipment_id')->all();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $notify = [];
        foreach ($ids as $shipmentId) {
            $shipment = Shipment::whereKey($shipmentId)->lockForUpdate()->first();
            if (! $shipment || ! $shipment->current_step || $shipment->type !== ItemType::PREORDER->value) {
                continue;
            }
            $order = Order::findOrFail($shipment->order_id);
            if (! in_array($order->status, self::ELIGIBLE_ORDER_STATUSES, true)) {
                continue;
            }
            $items = OrderItem::with('arrival')->where('shipment_id', $shipmentId)->where('item_type', ItemType::PREORDER->value)->get();
            $target = Propagation::computeShipmentTarget($items->map(fn ($i) => [
                'arrivalStep' => $i->arrival?->current_step, 'quantity' => $i->quantity, 'receivedQty' => $i->received_qty,
            ])->all());
            $last = null;
            foreach (Propagation::stepsBetween($shipment->current_step, $target) as $step) {
                $last = $this->tracking->applyStep($shipment, $order, [
                    'step' => $step,
                    'publicComment' => $step === $target ? $publicComment : null,
                    'internalNote' => $step === $target ? $internalNote : null,
                    'adminUserId' => $adminUserId,
                    'arrivalId' => $arrival->id,
                ], $effects);
            }
            if ($last) {
                $notify[] = $last;
            }
        }

        return $notify;
    }

    public function setStep(int $id, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        if ($dto['step'] === ShipmentStep::RECEIVED_IN_STOCK->value) {
            throw new HttpException(400, 'Utilisez la réception pour pointer les quantités reçues');
        }
        $force = ! empty($dto['force']);
        if ($force && (! $actor->isManager() || trim($dto['justification'] ?? '') === '')) {
            throw new HttpException(403, 'Seul un super-administrateur ou un gérant peut sauter une étape, avec une justification');
        }
        $effects = new StockEffects;
        $notify = DB::transaction(function () use ($id, $dto, $actor, $force, $effects) {
            $a = Arrival::whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404, 'Arrivage introuvable');
            $cur = array_search($a->current_step, Domain::ARRIVAL_STEPS, true);
            $next = array_search($dto['step'], Domain::ARRIVAL_STEPS, true);
            if ($next === false) {
                throw new HttpException(400, 'Étape non applicable à un arrivage');
            }
            if ($next <= $cur) {
                throw new HttpException(400, 'Cette étape a déjà été enregistrée');
            }
            if ($next !== $cur + 1 && ! $force) {
                throw new HttpException(400, 'Prochaine étape attendue : « '.Domain::STEP_LABELS[Domain::ARRIVAL_STEPS[$cur + 1]].' »');
            }
            $now = now();
            $patch = ['current_step' => $dto['step']];
            if ($dto['step'] === ShipmentStep::SHIPPED_FROM_CHINA->value && ! $a->shipped_at) {
                $patch['shipped_at'] = $now;
            }
            if ($dto['step'] === ShipmentStep::ARRIVED_IN_GABON->value) {
                $patch['arrived_at'] = $now;
            }
            $a->update($patch);
            $note = implode("\n", array_filter([$dto['internalNote'] ?? null, $force ? "Saut d'étape justifié : {$dto['justification']}" : null]));
            ArrivalEvent::create([
                'arrival_id' => $id, 'step' => $dto['step'], 'public_comment' => $dto['publicComment'] ?? null,
                'internal_note' => $note ?: null, 'admin_user_id' => $actor->id, 'occurred_at' => $now,
            ]);

            return $this->propagate($a, [], $dto['publicComment'] ?? null, $dto['internalNote'] ?? null, $actor->id, $effects);
        });
        $this->inventory->flush($effects);
        $this->emitAll($notify);
        $this->audit->log($actor->id, 'arrival.step', 'arrival', $id, null, $dto, $meta);

        return ['success' => true, 'updatedShipments' => count($notify)];
    }

    /** Réception : pointage des quantités reçues, entrée en stock réservée aux commandes, alerte des manquants. */
    public function receive(int $id, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $effects = new StockEffects;
        ['notify' => $notify, 'missing' => $missing, 'reference' => $reference] = DB::transaction(function () use ($id, $dto, $actor, $effects) {
            $a = Arrival::whereKey($id)->lockForUpdate()->first() ?? throw new HttpException(404, 'Arrivage introuvable');
            if (! in_array($a->current_step, [ShipmentStep::ARRIVED_IN_GABON->value, ShipmentStep::RECEIVED_IN_STOCK->value], true)) {
                throw new HttpException(400, "L'arrivage doit être à l'étape « Arrivé au Gabon » avant la réception");
            }
            $items = OrderItem::where('arrival_id', $id)->get()->keyBy('id');
            foreach ($dto['lines'] as $line) {
                $item = $items[$line['itemId']] ?? throw new HttpException(400, "Ligne {$line['itemId']} absente de cet arrivage");
                $qty = min((int) $line['receivedQty'], $item->quantity);
                $delta = $qty - $item->received_qty;
                if ($delta > 0 && $item->variant_id) {
                    $this->inventory->receive(['variantId' => $item->variant_id, 'quantity' => $delta],
                        ['orderId' => $item->order_id, 'arrivalId' => $id, 'adminUserId' => $actor->id], "Réception arrivage {$a->reference}", true, $effects);
                } elseif ($delta < 0) {
                    throw new HttpException(400, 'La quantité reçue ne peut pas diminuer après pointage');
                }
                OrderItem::whereKey($item->id)->update(['received_qty' => $qty, 'stock_reserved' => $qty > 0 || $item->stock_reserved]);
                $item->received_qty = $qty;
            }
            $missingQty = $items->sum(fn ($i) => max(0, $i->quantity - $i->received_qty));
            if ($a->current_step !== ShipmentStep::RECEIVED_IN_STOCK->value) {
                $now = now();
                $a->update(['current_step' => ShipmentStep::RECEIVED_IN_STOCK->value, 'received_at' => $now]);
                $note = implode("\n", array_filter([$dto['internalNote'] ?? null, $missingQty ? "{$missingQty} article(s) manquant(s)" : null]));
                ArrivalEvent::create([
                    'arrival_id' => $id, 'step' => ShipmentStep::RECEIVED_IN_STOCK->value, 'public_comment' => $dto['publicComment'] ?? null,
                    'internal_note' => $note ?: null, 'admin_user_id' => $actor->id, 'occurred_at' => $now,
                ]);
            }
            $n = $this->propagate($a, [], $dto['publicComment'] ?? 'Vos articles sont arrivés dans notre stock à Libreville.', $dto['internalNote'] ?? null, $actor->id, $effects);

            return ['notify' => $n, 'missing' => $missingQty, 'reference' => $a->reference];
        });
        $this->inventory->flush($effects);
        $this->emitAll($notify);
        if ($missing > 0) {
            DomainEvent::emit(DomainEvent::ARRIVAL_MISSING, ['arrivalId' => $id, 'reference' => $reference, 'missing' => $missing]);
        }
        $this->audit->log($actor->id, 'arrival.receive', 'arrival', $id, null, $dto + ['missing' => $missing], $meta);

        return ['success' => true, 'missing' => $missing, 'updatedShipments' => count($notify)];
    }

    public function addDocument(int $id, array $dto): array
    {
        if (! Arrival::whereKey($id)->exists()) {
            throw new HttpException(404, 'Arrivage introuvable');
        }

        return ArrivalDocument::create(['arrival_id' => $id, 'name' => $dto['name'], 'url' => $dto['url'], 'mime_type' => $dto['mimeType']])->refresh()->toArray();
    }

    public function removeDocument(int $docId): array
    {
        ArrivalDocument::whereKey($docId)->delete();

        return ['success' => true];
    }

    /** Alerte quotidienne : arrivages dont la date estimée est dépassée. */
    public function flagLateArrivals(): void
    {
        try {
            $late = Arrival::where('estimated_arrival_at', '<', now())
                ->whereIn('current_step', [ShipmentStep::ORDERED_FROM_SUPPLIER->value, ShipmentStep::SHIPPED_FROM_CHINA->value, ShipmentStep::IN_INTERNATIONAL_TRANSIT->value])
                ->get();
            foreach ($late as $a) {
                AdminNotification::create([
                    'type' => AdminNotificationType::ARRIVAL_DELAYED->value,
                    'title' => "Arrivage {$a->reference} en retard (prévu le ".$a->estimated_arrival_at?->format('d/m/Y').')',
                    'link' => "/arrivages/{$a->id}",
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Contrôle des arrivages en retard impossible : {$e->getMessage()}");
        }
    }
}
