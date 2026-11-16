<?php

namespace App\Services\Inventory;

use App\Enums\AvailabilityMode;
use App\Enums\StockMovementType;
use App\Events\DomainEvent;
use App\Models\BackInStockAlert;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\AuditService;
use App\Services\Catalog\ProductIndexer;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Stocks : réservation (anti-survente), libération, sortie à la livraison, entrée, ajustement.
 * Les méthodes reserve/release/convert/receive doivent être appelées dans une transaction.
 *
 * Contexte d'un mouvement : ['orderId' => ?, 'arrivalId' => ?, 'adminUserId' => ?]
 */
class InventoryService
{
    public function __construct(
        private readonly ProductIndexer $indexer,
        private readonly AuditService $audit,
    ) {}

    /**
     * Verrouille les variantes (SELECT … FOR UPDATE) dans un ordre déterministe pour éviter les interblocages.
     *
     * @return array<int, ProductVariant>
     */
    public function lockVariants(array $ids): array
    {
        $unique = array_values(array_unique(array_map('intval', $ids)));
        sort($unique);
        if (! $unique) {
            return [];
        }
        $rows = ProductVariant::whereIn('id', $unique)->orderBy('id')->lockForUpdate()->get();
        $products = Product::withTrashed()->whereIn('id', $rows->pluck('product_id')->unique())->get()->keyBy('id');
        $out = [];
        foreach ($rows as $r) {
            $r->setRelation('product', $products[$r->product_id]);
            $out[$r->id] = $r;
        }

        return $out;
    }

    private function record(ProductVariant $v, StockMovementType $type, int $quantity, string $reason, array $ctx): void
    {
        StockMovement::create([
            'variant_id' => $v->id,
            'type' => $type->value,
            'quantity' => $quantity,
            'reason' => mb_substr($reason, 0, 255),
            'on_hand_after' => $v->stock_on_hand,
            'reserved_after' => $v->stock_reserved,
            'order_id' => $ctx['orderId'] ?? null,
            'arrival_id' => $ctx['arrivalId'] ?? null,
            'admin_user_id' => $ctx['adminUserId'] ?? null,
        ]);
    }

    /** Bascule automatique de disponibilité + détection des stocks faibles / retours en stock. */
    private function afterChange(ProductVariant $v, int $prevAvailable, StockEffects $effects): void
    {
        $p = $v->product;
        $available = $v->stock_on_hand - $v->stock_reserved;
        $mode = $v->availability_mode ?? $p->availability_mode;
        $patch = [];
        if ($available <= 0 && $mode === AvailabilityMode::IN_STOCK->value && ! $v->auto_switched) {
            $patch = ['availability_mode' => $p->auto_preorder_when_out_of_stock ? AvailabilityMode::PREORDER->value : AvailabilityMode::UNAVAILABLE->value, 'auto_switched' => true];
        } elseif ($available > 0 && $v->auto_switched) {
            $patch = ['availability_mode' => null, 'auto_switched' => false];
            $effects->backInStock[$v->id] = true;
        } elseif ($available > 0 && $prevAvailable <= 0 && $mode === AvailabilityMode::IN_STOCK->value) {
            $effects->backInStock[$v->id] = true;
        }
        if ($patch) {
            ProductVariant::whereKey($v->id)->update($patch);
            $v->forceFill($patch);
        }
        $crossedThreshold = $prevAvailable > $v->low_stock_threshold && $available <= $v->low_stock_threshold;
        $hitZero = $available <= 0 && $prevAvailable > 0;
        if ($crossedThreshold || $hitZero) {
            $effects->lowStock[$v->id] = true;
        }
        $effects->addProduct($v->product_id);
    }

    /**
     * Réserve le stock des articles « en stock » d'une commande. Refuse en cas de stock insuffisant.
     *
     * @param  list<array{variantId: int, quantity: int, label?: string}>  $lines
     */
    public function reserve(array $lines, int $orderId, StockEffects $effects): void
    {
        $locked = $this->lockVariants(array_column($lines, 'variantId'));
        foreach ($lines as $line) {
            $v = $locked[$line['variantId']] ?? throw new HttpException(404, 'Article introuvable');
            $available = $v->stock_on_hand - $v->stock_reserved;
            if ($available < $line['quantity']) {
                $name = $line['label'] ?? "{$v->product->name} – {$v->name}";
                throw new HttpException(409, $available > 0
                    ? "Stock insuffisant pour « {$name} » : seulement {$available} disponible(s)."
                    : "Désolé, « {$name} » vient d'être épuisé. Retirez-le de votre panier pour continuer.");
            }
            $v->stock_reserved += $line['quantity'];
            ProductVariant::whereKey($v->id)->update(['stock_reserved' => $v->stock_reserved]);
            $this->record($v, StockMovementType::RESERVE, $line['quantity'], 'Réservation commande', ['orderId' => $orderId]);
            $this->afterChange($v, $available, $effects);
        }
    }

    public function release(array $lines, array $ctx, string $reason, StockEffects $effects): void
    {
        $locked = $this->lockVariants(array_column($lines, 'variantId'));
        foreach ($lines as $line) {
            $v = $locked[$line['variantId']] ?? null;
            if (! $v) {
                continue;
            }
            $prev = $v->stock_on_hand - $v->stock_reserved;
            $qty = min($line['quantity'], $v->stock_reserved);
            if ($qty <= 0) {
                continue;
            }
            $v->stock_reserved -= $qty;
            ProductVariant::whereKey($v->id)->update(['stock_reserved' => $v->stock_reserved]);
            $this->record($v, StockMovementType::RELEASE, $qty, $reason, $ctx);
            $this->afterChange($v, $prev, $effects);
        }
    }

    /** Conversion de la réservation en sortie physique (livraison). */
    public function convert(array $lines, array $ctx, StockEffects $effects): void
    {
        $locked = $this->lockVariants(array_column($lines, 'variantId'));
        foreach ($lines as $line) {
            $v = $locked[$line['variantId']] ?? null;
            if (! $v) {
                continue;
            }
            $prev = $v->stock_on_hand - $v->stock_reserved;
            $v->stock_on_hand = max(0, $v->stock_on_hand - $line['quantity']);
            $v->stock_reserved = max(0, $v->stock_reserved - $line['quantity']);
            ProductVariant::whereKey($v->id)->update(['stock_on_hand' => $v->stock_on_hand, 'stock_reserved' => $v->stock_reserved]);
            $this->record($v, StockMovementType::OUT, -$line['quantity'], 'Sortie – livraison', $ctx);
            $this->afterChange($v, $prev, $effects);
        }
    }

    /** Entrée de stock (réception d'arrivage, retour), avec réservation optionnelle pour la commande liée. */
    public function receive(array $line, array $ctx, string $reason, bool $reserve, StockEffects $effects): void
    {
        $v = $this->lockVariants([$line['variantId']])[$line['variantId']] ?? null;
        if (! $v || $line['quantity'] <= 0) {
            return;
        }
        $prev = $v->stock_on_hand - $v->stock_reserved;
        $v->stock_on_hand += $line['quantity'];
        ProductVariant::whereKey($v->id)->update(['stock_on_hand' => $v->stock_on_hand]);
        $this->record($v, StockMovementType::IN, $line['quantity'], $reason, $ctx);
        if ($reserve) {
            $v->stock_reserved += $line['quantity'];
            ProductVariant::whereKey($v->id)->update(['stock_reserved' => $v->stock_reserved]);
            $this->record($v, StockMovementType::RESERVE, $line['quantity'], 'Réservation précommande reçue', $ctx);
        }
        $this->afterChange($v, $prev, $effects);
    }

    /** Déclenche les effets après COMMIT. */
    public function flush(StockEffects $effects): void
    {
        $this->indexer->refresh(array_keys($effects->productIds));
        foreach (array_keys($effects->lowStock) as $variantId) {
            DomainEvent::emit(DomainEvent::STOCK_LOW, ['variantId' => $variantId]);
        }
        foreach (array_keys($effects->backInStock) as $variantId) {
            DomainEvent::emit(DomainEvent::STOCK_BACK, ['variantId' => $variantId]);
        }
    }

    public function adjust(int $variantId, array $dto, int $adminUserId, RequestMeta $meta): array
    {
        $effects = new StockEffects;
        $v = DB::transaction(function () use ($variantId, $dto, $adminUserId, $meta, $effects) {
            $v = $this->lockVariants([$variantId])[$variantId] ?? throw new HttpException(404, 'Variante introuvable');
            $before = ['stockOnHand' => $v->stock_on_hand, 'stockReserved' => $v->stock_reserved];
            $prev = $v->stock_on_hand - $v->stock_reserved;
            $newOnHand = $dto['mode'] === 'set' ? (int) $dto['value'] : $v->stock_on_hand + (int) $dto['value'];
            if ($newOnHand < 0) {
                throw new HttpException(400, 'Le stock physique ne peut pas être négatif');
            }
            if ($newOnHand < $v->stock_reserved) {
                throw new HttpException(400, "Le stock physique ne peut pas être inférieur au stock réservé ({$v->stock_reserved})");
            }
            $delta = $newOnHand - $v->stock_on_hand;
            if ($delta === 0) {
                return $v;
            }
            $v->stock_on_hand = $newOnHand;
            ProductVariant::whereKey($v->id)->update(['stock_on_hand' => $newOnHand]);
            $type = ($dto['type'] ?? null) === 'IN' && $delta > 0 ? StockMovementType::IN : StockMovementType::ADJUST;
            $this->record($v, $type, $delta, $dto['reason'], ['adminUserId' => $adminUserId]);
            $this->afterChange($v, $prev, $effects);
            $this->audit->log($adminUserId, 'stock.adjust', 'product_variant', $v->id, $before,
                ['stockOnHand' => $v->stock_on_hand, 'stockReserved' => $v->stock_reserved, 'reason' => $dto['reason']], $meta);

            return $v;
        });
        $this->flush($effects);

        return ['id' => $v->id, 'stockOnHand' => $v->stock_on_hand, 'stockReserved' => $v->stock_reserved, 'stockAvailable' => $v->stock_on_hand - $v->stock_reserved];
    }

    public function overview(array $q): array
    {
        $qb = ProductVariant::query()->select('product_variants.*')
            ->join('products as p', 'p.id', '=', 'product_variants.product_id')
            ->whereNull('p.deleted_at')
            ->with('product.category');
        if (! empty($q['q'])) {
            $like = "%{$q['q']}%";
            $qb->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('p.reference', 'like', $like)->orWhere('product_variants.sku', 'like', $like));
        }
        if (! empty($q['categoryId'])) {
            $qb->where('p.category_id', $q['categoryId']);
        }
        if (! empty($q['lowStock'])) {
            $qb->whereRaw('(product_variants.stock_on_hand - product_variants.stock_reserved) <= product_variants.low_stock_threshold AND product_variants.is_active = 1');
        }
        $total = (clone $qb)->count();
        $rows = $qb->orderByRaw('(product_variants.stock_on_hand - product_variants.stock_reserved) ASC')->orderBy('p.name')
            ->forPage($q['page'], $q['limit'])->get();

        return Paginated::make($rows->map(fn (ProductVariant $v) => [
            'variantId' => $v->id,
            'productId' => $v->product_id,
            'productName' => $v->product->name,
            'reference' => $v->product->reference,
            'category' => $v->product->category?->name,
            'sku' => $v->sku,
            'variantName' => $v->name,
            'stockOnHand' => $v->stock_on_hand,
            'stockReserved' => $v->stock_reserved,
            'stockAvailable' => $v->stockAvailable(),
            'lowStockThreshold' => $v->low_stock_threshold,
            'availabilityMode' => $v->availability_mode ?? $v->product->availability_mode,
            'costPrice' => $v->cost_price ?? $v->product->cost_price,
            'isLow' => $v->stockAvailable() <= $v->low_stock_threshold,
        ])->all(), $total, $q);
    }

    public function movementsList(array $q): array
    {
        $qb = StockMovement::query()->select('stock_movements.*')
            ->join('product_variants as v', 'v.id', '=', 'stock_movements.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->with(['variant.product', 'adminUser']);
        if (! empty($q['variantId'])) {
            $qb->where('stock_movements.variant_id', $q['variantId']);
        }
        if (! empty($q['type'])) {
            $qb->where('stock_movements.type', $q['type']);
        }
        if (! empty($q['orderId'])) {
            $qb->where('stock_movements.order_id', $q['orderId']);
        }
        if (! empty($q['q'])) {
            $like = "%{$q['q']}%";
            $qb->where(fn ($w) => $w->where('p.name', 'like', $like)->orWhere('v.sku', 'like', $like));
        }
        if (! empty($q['from'])) {
            $qb->where('stock_movements.created_at', '>=', Carbon::parse($q['from']));
        }
        if (! empty($q['to'])) {
            $qb->where('stock_movements.created_at', '<=', Carbon::parse($q['to']));
        }
        $total = (clone $qb)->count();
        $rows = $qb->orderByDesc('stock_movements.created_at')->forPage($q['page'], $q['limit'])->get();

        return Paginated::make($rows->map(fn (StockMovement $m) => [
            'id' => $m->id,
            'createdAt' => \App\Support\Format::iso($m->created_at),
            'type' => $m->type,
            'quantity' => $m->quantity,
            'reason' => $m->reason,
            'onHandAfter' => $m->on_hand_after,
            'reservedAfter' => $m->reserved_after,
            'orderId' => $m->order_id,
            'arrivalId' => $m->arrival_id,
            'sku' => $m->variant->sku,
            'productName' => $m->variant->product?->name,
            'adminUser' => $m->adminUser?->full_name,
        ])->all(), $total, $q);
    }

    public function alertsSummary(): array
    {
        $rows = DB::select(
            'SELECT a.variant_id AS variantId, p.name AS productName, v.name AS variantName, v.sku AS sku,
                    v.stock_on_hand - v.stock_reserved AS stockAvailable,
                    SUM(CASE WHEN a.notified_at IS NULL THEN 1 ELSE 0 END) AS pending, COUNT(a.id) AS total
             FROM back_in_stock_alerts a
             INNER JOIN product_variants v ON v.id = a.variant_id
             INNER JOIN products p ON p.id = v.product_id
             GROUP BY a.variant_id, p.name, v.name, v.sku, v.stock_on_hand, v.stock_reserved
             ORDER BY pending DESC',
        );

        return array_map(fn ($r) => [
            'variantId' => (int) $r->variantId, 'productName' => $r->productName, 'variantName' => $r->variantName, 'sku' => $r->sku,
            'stockAvailable' => (int) $r->stockAvailable, 'pending' => (int) $r->pending, 'total' => (int) $r->total,
        ], $rows);
    }

    /** Envoi groupé des alertes « retour en stock » d'une variante. */
    public function sendAlerts(int $variantId): array
    {
        $pending = BackInStockAlert::where('variant_id', $variantId)->whereNull('notified_at')->count();
        DomainEvent::emit(DomainEvent::STOCK_BACK, ['variantId' => $variantId]);

        return ['queued' => $pending];
    }
}
