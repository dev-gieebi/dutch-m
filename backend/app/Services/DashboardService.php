<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

/** Tableau de bord du back-office : indicateurs, compteurs « à traiter », graphiques. */
class DashboardService
{
    private static function one(string $sql, array $params = []): object
    {
        return DB::selectOne($sql, $params);
    }

    private static function n(mixed $v): int|float
    {
        return is_numeric($v) ? $v + 0 : 0;
    }

    private function kpis($start, $end): array
    {
        $rev = self::one("SELECT COALESCE(SUM(amount),0) AS revenue FROM payments WHERE status IN ('SUCCEEDED','PARTIALLY_REFUNDED','REFUNDED') AND is_duplicate = 0 AND paid_at BETWEEN ? AND ?", [$start, $end]);
        $ref = self::one("SELECT COALESCE(SUM(amount),0) AS refunded FROM refunds WHERE status = 'SUCCEEDED' AND created_at BETWEEN ? AND ?", [$start, $end]);
        $ord = self::one("SELECT COUNT(*) AS cnt, COALESCE(AVG(total),0) AS avgBasket, COALESCE(SUM(total),0) AS ordered FROM orders WHERE status NOT IN ('CANCELLED') AND created_at BETWEEN ? AND ?", [$start, $end]);

        return [
            'revenue' => self::n($rev->revenue) - self::n($ref->refunded),
            'orders' => (int) $ord->cnt,
            'averageBasket' => (int) round((float) $ord->avgBasket),
            'orderedAmount' => (int) $ord->ordered,
        ];
    }

    public function get(string $period = 'today'): array
    {
        $end = now();
        $start = now()->startOfDay();
        if ($period === '7d') {
            $start->subDays(6);
        } elseif ($period === '30d') {
            $start->subDays(29);
        }

        $toProcess = self::one("SELECT COUNT(*) c FROM orders WHERE status IN ('CONFIRMED', 'IN_PROGRESS')");
        $pendingPayments = self::one("SELECT COUNT(*) c FROM payments WHERE status = 'PENDING'");
        $awaitingCash = self::one("SELECT COUNT(*) c, COALESCE(SUM(balance_due),0) amount FROM orders WHERE payment_status = 'AWAITING_CASH' AND status NOT IN ('CANCELLED')");
        $delayed = self::one("SELECT COUNT(*) c FROM shipments WHERE current_step NOT IN ('DELIVERED','CANCELLED','RETURNED') AND current_step IS NOT NULL AND (is_delayed = 1 OR estimated_delivery_date < NOW())");
        $lowStock = self::one("SELECT COUNT(*) c, SUM(CASE WHEN v.stock_on_hand - v.stock_reserved <= 0 THEN 1 ELSE 0 END) outOfStock FROM product_variants v INNER JOIN products p ON p.id = v.product_id
            WHERE v.is_active = 1 AND p.deleted_at IS NULL AND p.availability_mode = 'IN_STOCK' AND (v.stock_on_hand - v.stock_reserved) <= v.low_stock_threshold");
        $unread = self::one('SELECT COALESCE(SUM(unread_for_admin),0) c FROM conversations');
        $arrivals = self::one("SELECT COUNT(*) c FROM arrivals WHERE current_step IN ('ORDERED_FROM_SUPPLIER','SHIPPED_FROM_CHINA','IN_INTERNATIONAL_TRANSIT','ARRIVED_IN_GABON')
            AND estimated_arrival_at BETWEEN NOW() - INTERVAL 1 DAY AND NOW() + INTERVAL 7 DAY");

        $chartStart = now()->startOfDay()->subDays($period === '30d' ? 29 : 6);
        $salesByDay = DB::select("SELECT DATE(paid_at) day, SUM(amount) amount, COUNT(*) count FROM payments WHERE status IN ('SUCCEEDED','PARTIALLY_REFUNDED','REFUNDED') AND is_duplicate = 0 AND paid_at >= ? GROUP BY DATE(paid_at) ORDER BY day", [$chartStart]);
        $byCategory = DB::select("SELECT COALESCE(c.name, 'Sans catégorie') name, SUM(i.line_total) amount FROM order_items i INNER JOIN orders o ON o.id = i.order_id
            LEFT JOIN products p ON p.id = i.product_id LEFT JOIN categories c ON c.id = p.category_id
            WHERE o.status NOT IN ('CANCELLED','PENDING_PAYMENT') AND o.created_at BETWEEN ? AND ? GROUP BY c.name ORDER BY amount DESC", [$chartStart, $end]);
        $topProducts = DB::select("SELECT i.product_name name, SUM(i.quantity) quantity, SUM(i.line_total) amount FROM order_items i INNER JOIN orders o ON o.id = i.order_id
            WHERE o.status NOT IN ('CANCELLED','PENDING_PAYMENT') AND o.created_at BETWEEN ? AND ? GROUP BY i.product_name ORDER BY quantity DESC LIMIT 10", [$chartStart, $end]);
        $byPaymentMethod = DB::select("SELECT payment_method method, COUNT(*) count, SUM(total) amount FROM orders WHERE status NOT IN ('CANCELLED') AND created_at BETWEEN ? AND ? GROUP BY payment_method", [$chartStart, $end]);
        $latestOrders = DB::select('SELECT id, number, contact_name contactName, total, status, payment_status paymentStatus, payment_method paymentMethod, created_at createdAt FROM orders ORDER BY created_at DESC LIMIT 10');
        $latestMessages = DB::select('SELECT c.id, c.visitor_name visitorName, c.status, c.unread_for_admin unread, c.last_message_at lastMessageAt,
            (SELECT body FROM messages m WHERE m.conversation_id = c.id AND m.is_internal = 0 ORDER BY m.id DESC LIMIT 1) lastMessage
            FROM conversations c ORDER BY c.last_message_at DESC LIMIT 6');

        return [
            'period' => $period,
            'kpis' => $this->kpis($start, $end),
            'counters' => [
                'ordersToProcess' => (int) $toProcess->c,
                'pendingPayments' => (int) $pendingPayments->c,
                'awaitingCash' => (int) $awaitingCash->c,
                'awaitingCashAmount' => (int) $awaitingCash->amount,
                'delayedShipments' => (int) $delayed->c,
                'lowStock' => (int) $lowStock->c,
                'outOfStock' => (int) $lowStock->outOfStock,
                'unreadMessages' => (int) $unread->c,
                'arrivalsThisWeek' => (int) $arrivals->c,
            ],
            'charts' => [
                'salesByDay' => array_map(fn ($r) => ['day' => $r->day, 'amount' => (int) $r->amount, 'count' => (int) $r->count], $salesByDay),
                'salesByCategory' => array_map(fn ($r) => ['name' => $r->name, 'amount' => (int) $r->amount], $byCategory),
                'topProducts' => array_map(fn ($r) => ['name' => $r->name, 'quantity' => (int) $r->quantity, 'amount' => (int) $r->amount], $topProducts),
                'byPaymentMethod' => array_map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->count, 'amount' => (int) $r->amount], $byPaymentMethod),
            ],
            'latestOrders' => array_map(fn ($r) => ['id' => (int) $r->id, 'number' => $r->number, 'contactName' => $r->contactName, 'total' => (int) $r->total,
                'status' => $r->status, 'paymentStatus' => $r->paymentStatus, 'paymentMethod' => $r->paymentMethod, 'createdAt' => Format::iso($r->createdAt)], $latestOrders),
            'latestMessages' => array_map(fn ($r) => ['id' => (int) $r->id, 'visitorName' => $r->visitorName, 'status' => $r->status, 'unread' => (int) $r->unread,
                'lastMessageAt' => Format::iso($r->lastMessageAt), 'lastMessage' => $r->lastMessage], $latestMessages),
        ];
    }
}
