<?php

namespace App\Services;

use App\Support\Csv;
use App\Support\Domain;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Rapports (ventes, précommandes, valorisation du stock) et exports CSV / Excel. */
class ReportsService
{
    /** @return array{0: Carbon, 1: Carbon} */
    private function range(?string $from, ?string $to): array
    {
        $end = $to ? Carbon::parse($to) : now();
        if ($to && strlen($to) <= 10) {
            $end->endOfDay();
        }
        $start = $from ? Carbon::parse($from) : $end->copy()->startOfMonth();

        return [$start, $end];
    }

    private static function rows(string $sql, array $params = []): array
    {
        return array_map(fn ($r) => (array) $r, DB::select($sql, $params));
    }

    public function sales(array $q): array
    {
        [$from, $to] = $this->range($q['from'] ?? null, $q['to'] ?? null);
        $valid = "o.status NOT IN ('CANCELLED','PENDING_PAYMENT') AND o.created_at BETWEEN ? AND ?";
        $p = [$from, $to];
        $groupBy = $q['groupBy'] ?? 'day';
        $rows = match ($groupBy) {
            'month' => self::rows("SELECT DATE_FORMAT(o.created_at, '%Y-%m') label, COUNT(*) orders, SUM(o.total) total, SUM(o.discount_total) discount, SUM(o.shipping_total) shipping FROM orders o WHERE {$valid} GROUP BY label ORDER BY label", $p),
            'product' => self::rows("SELECT CONCAT(i.product_name, ' (', i.reference, ')') label, SUM(i.quantity) quantity, SUM(i.line_total) total FROM order_items i INNER JOIN orders o ON o.id = i.order_id WHERE {$valid} GROUP BY i.product_name, i.reference ORDER BY total DESC", $p),
            'category' => self::rows("SELECT COALESCE(c.name,'Sans catégorie') label, SUM(i.quantity) quantity, SUM(i.line_total) total FROM order_items i INNER JOIN orders o ON o.id = i.order_id LEFT JOIN products p ON p.id = i.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE {$valid} GROUP BY c.name ORDER BY total DESC", $p),
            'paymentMethod' => array_map(fn ($r) => ['label' => Domain::label(Domain::PAYMENT_METHOD_LABELS, $r['label'])] + $r,
                self::rows("SELECT o.payment_method label, COUNT(*) orders, SUM(o.total) total, SUM(o.amount_paid) paid FROM orders o WHERE {$valid} GROUP BY o.payment_method", $p)),
            'zone' => self::rows("SELECT COALESCE(CONCAT(o.city, ' – ', o.zone_name), 'Retrait en boutique') label, COUNT(*) orders, SUM(o.total) total, SUM(o.shipping_total) shipping FROM orders o WHERE {$valid} GROUP BY label ORDER BY total DESC", $p),
            default => self::rows("SELECT DATE_FORMAT(o.created_at, '%Y-%m-%d') label, COUNT(*) orders, SUM(o.total) total, SUM(o.discount_total) discount, SUM(o.shipping_total) shipping FROM orders o WHERE {$valid} GROUP BY label ORDER BY label", $p),
        };
        $summary = DB::selectOne("SELECT COUNT(*) orders, COALESCE(SUM(o.total),0) total, COALESCE(SUM(o.amount_paid),0) paid, COALESCE(AVG(o.total),0) avgBasket FROM orders o WHERE {$valid}", $p);

        return [
            'from' => Format::iso($from),
            'to' => Format::iso($to),
            'groupBy' => $groupBy,
            'summary' => ['orders' => (int) $summary->orders, 'total' => (int) $summary->total, 'paid' => (int) $summary->paid, 'averageBasket' => (int) round((float) $summary->avgBasket)],
            'rows' => array_map(function ($r) {
                $out = [];
                foreach ($r as $k => $v) {
                    $out[$k] = $k === 'label' ? $v : (int) $v;
                }

                return $out;
            }, $rows),
        ];
    }

    public function preorders(): array
    {
        $lines = self::rows("SELECT o.id orderId, o.number, o.contact_name customer, i.product_name product, i.reference, i.quantity, i.received_qty receivedQty,
            a.reference arrival, a.current_step arrivalStep, s.current_step shipmentStep, s.estimated_delivery_date estimatedDate, s.is_delayed isDelayed, s.delay_reason delayReason
            FROM order_items i INNER JOIN orders o ON o.id = i.order_id LEFT JOIN arrivals a ON a.id = i.arrival_id LEFT JOIN shipments s ON s.id = i.shipment_id
            WHERE i.item_type = 'PREORDER' AND o.status IN ('CONFIRMED','IN_PROGRESS','PARTIALLY_DELIVERED') AND (s.current_step IS NULL OR s.current_step NOT IN ('DELIVERED','CANCELLED'))
            ORDER BY s.estimated_delivery_date ASC");
        $now = now();

        return array_map(fn ($l) => array_merge($l, [
            'orderId' => (int) $l['orderId'],
            'quantity' => (int) $l['quantity'],
            'receivedQty' => (int) $l['receivedQty'],
            'isDelayed' => (int) $l['isDelayed'],
            'estimatedDate' => Format::iso($l['estimatedDate']),
            'isLate' => (bool) $l['isDelayed'] || ($l['estimatedDate'] && Carbon::parse($l['estimatedDate'])->lt($now)),
            'shipmentStepLabel' => $l['shipmentStep'] ? Domain::STEP_LABELS[$l['shipmentStep']] : null,
            'arrivalStepLabel' => $l['arrivalStep'] ? Domain::STEP_LABELS[$l['arrivalStep']] : null,
        ]), $lines);
    }

    public function stockValuation(): array
    {
        $rows = array_map(fn ($r) => array_merge($r, [
            'onHand' => (int) $r['onHand'], 'reserved' => (int) $r['reserved'], 'unitCost' => (int) $r['unitCost'],
            'unitPrice' => (int) $r['unitPrice'], 'costValue' => (int) $r['costValue'], 'retailValue' => (int) $r['retailValue'],
        ]), self::rows("SELECT p.reference, p.name product, v.sku, v.name variant, COALESCE(c.name,'') category, v.stock_on_hand onHand, v.stock_reserved reserved,
            COALESCE(v.cost_price, p.cost_price, 0) unitCost, v.price unitPrice,
            v.stock_on_hand * COALESCE(v.cost_price, p.cost_price, 0) costValue, v.stock_on_hand * v.price retailValue
            FROM product_variants v INNER JOIN products p ON p.id = v.product_id LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.deleted_at IS NULL AND v.is_active = 1 ORDER BY costValue DESC"));

        return [
            'rows' => $rows,
            'totals' => [
                'units' => array_sum(array_column($rows, 'onHand')),
                'costValue' => array_sum(array_column($rows, 'costValue')),
                'retailValue' => array_sum(array_column($rows, 'retailValue')),
            ],
        ];
    }

    /** Construit le tableau exportable d'une entité. @return array{title: string, columns: list<array>, rows: list<array>} */
    public function table(string $entity, array $q): array
    {
        [$from, $to] = $this->range($q['from'] ?? '2000-01-01', $q['to'] ?? null);
        $c = fn (string $key, string $header, int $width = 14, bool $money = false) => compact('key', 'header', 'width', 'money');
        $label = fn (array $labels, $v) => $v === null ? null : Domain::label($labels, $v);
        $yesNo = fn ($v) => $v ? 'Oui' : 'Non';

        switch ($entity) {
            case 'orders':
                return [
                    'title' => 'Commandes',
                    'columns' => [
                        $c('number', 'N° commande', 18), $c('createdAt', 'Date', 20), $c('contactName', 'Client', 24), $c('contactPhone', 'Téléphone', 16),
                        $c('contactEmail', 'E-mail', 24), $c('city', 'Ville'), $c('zone', 'Zone', 18), $c('orderType', 'Type'), $c('status', 'Statut', 20),
                        $c('paymentStatus', 'Paiement', 22), $c('paymentMethod', 'Moyen', 22), $c('subtotal', 'Sous-total', 14, true), $c('discount', 'Remise', 14, true),
                        $c('shipping', 'Livraison', 14, true), $c('total', 'Total', 14, true), $c('deposit', 'Acompte', 14, true), $c('paid', 'Payé', 14, true),
                        $c('balance', 'Reste', 14, true), $c('source', 'Source'),
                    ],
                    'rows' => array_map(fn ($r) => array_merge($r, [
                        'status' => $label(Domain::ORDER_STATUS_LABELS, $r['status']),
                        'paymentStatus' => $label(Domain::PAYMENT_STATUS_LABELS, $r['paymentStatus']),
                        'paymentMethod' => $label(Domain::PAYMENT_METHOD_LABELS, $r['paymentMethod']),
                    ]), self::rows('SELECT number, created_at createdAt, contact_name contactName, contact_phone contactPhone, contact_email contactEmail, city, zone_name zone, order_type orderType,
                        status, payment_status paymentStatus, payment_method paymentMethod, subtotal, discount_total discount, shipping_total shipping, total, deposit_amount deposit, amount_paid paid, balance_due balance, source
                        FROM orders WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC', [$from, $to])),
                ];
            case 'order-items':
            case 'sales':
                $extra = $entity === 'sales' ? "AND o.status NOT IN ('CANCELLED','PENDING_PAYMENT')" : '';

                return [
                    'title' => $entity === 'sales' ? 'Ventes' : 'Lignes de commande',
                    'columns' => [
                        $c('number', 'N° commande', 18), $c('createdAt', 'Date', 20), $c('status', 'Statut', 18), $c('product', 'Produit', 30), $c('reference', 'Référence', 18),
                        $c('variant', 'Variante', 16), $c('category', 'Catégorie', 22), $c('itemType', 'Type'), $c('unitPrice', 'Prix unitaire', 14, true),
                        $c('quantity', 'Quantité'), $c('lineTotal', 'Total ligne', 14, true), $c('paymentMethod', 'Moyen de paiement', 22),
                    ],
                    'rows' => array_map(fn ($r) => array_merge($r, [
                        'status' => $label(Domain::ORDER_STATUS_LABELS, $r['status']),
                        'paymentMethod' => $label(Domain::PAYMENT_METHOD_LABELS, $r['paymentMethod']),
                    ]), self::rows("SELECT o.number, o.created_at createdAt, o.status, i.product_name product, i.reference, i.variant_name variant, i.item_type itemType, i.unit_price unitPrice, i.quantity, i.line_total lineTotal, COALESCE(c.name,'') category, o.payment_method paymentMethod
                        FROM order_items i INNER JOIN orders o ON o.id = i.order_id LEFT JOIN products p ON p.id = i.product_id LEFT JOIN categories c ON c.id = p.category_id
                        WHERE o.created_at BETWEEN ? AND ? {$extra} ORDER BY o.created_at DESC", [$from, $to])),
                ];
            case 'customers':
                return [
                    'title' => 'Clients',
                    'columns' => [
                        $c('fullName', 'Nom', 26), $c('phone', 'Téléphone', 16), $c('email', 'E-mail', 26), $c('isGuest', 'Invité'), $c('newsletter', 'Newsletter'),
                        $c('createdAt', 'Créé le', 20), $c('orders', 'Commandes'), $c('spent', 'Total dépensé', 14, true),
                    ],
                    'rows' => array_map(fn ($r) => array_merge($r, ['isGuest' => $yesNo($r['isGuest']), 'newsletter' => $yesNo($r['newsletter'])]),
                        self::rows("SELECT c.full_name fullName, c.phone, c.email, c.is_guest isGuest, c.newsletter_consent newsletter, c.created_at createdAt,
                            COUNT(o.id) orders, COALESCE(SUM(o.amount_paid - o.amount_refunded),0) spent
                            FROM customers c LEFT JOIN orders o ON o.customer_id = c.id AND o.status <> 'CANCELLED' WHERE c.deleted_at IS NULL GROUP BY c.id ORDER BY c.created_at DESC")),
                ];
            case 'products':
            case 'stock':
                return [
                    'title' => $entity === 'stock' ? 'Stocks' : 'Produits',
                    'columns' => [
                        $c('reference', 'Référence', 16), $c('name', 'Produit', 30), $c('category', 'Catégorie', 22), $c('status', 'Statut'), $c('availability', 'Disponibilité', 16),
                        $c('sku', 'SKU', 18), $c('variant', 'Variante', 16), $c('price', 'Prix', 14, true), $c('cost', "Coût d'achat", 14, true), $c('onHand', 'Physique'),
                        $c('reserved', 'Réservé'), $c('available', 'Disponible'), $c('threshold', 'Seuil'),
                    ],
                    'rows' => array_map(fn ($r) => array_merge($r, ['availability' => $label(Domain::AVAILABILITY_LABELS, $r['availability'])]),
                        self::rows("SELECT p.reference, p.name, COALESCE(c.name,'') category, p.status, p.availability_mode availability, v.sku, v.name variant, v.price, v.cost_price cost,
                            v.stock_on_hand onHand, v.stock_reserved reserved, v.stock_on_hand - v.stock_reserved available, v.low_stock_threshold threshold
                            FROM product_variants v INNER JOIN products p ON p.id = v.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE p.deleted_at IS NULL ORDER BY p.reference")),
                ];
            case 'payments':
                return [
                    'title' => 'Paiements',
                    'columns' => [
                        $c('createdAt', 'Créé le', 20), $c('paidAt', 'Payé le', 20), $c('number', 'Commande', 18), $c('provider', 'Opérateur'), $c('method', 'Moyen', 18),
                        $c('kind', 'Type'), $c('amount', 'Montant', 14, true), $c('refunded', 'Remboursé', 14, true), $c('status', 'Statut', 16), $c('payerPhone', 'Payeur', 16),
                        $c('providerReference', 'Référence', 22), $c('transactionId', 'Transaction', 22), $c('isManual', 'Manuel'), $c('isDuplicate', 'Doublon'),
                    ],
                    'rows' => array_map(fn ($r) => array_merge($r, ['isManual' => $yesNo($r['isManual']), 'isDuplicate' => $yesNo($r['isDuplicate'])]),
                        self::rows('SELECT p.created_at createdAt, p.paid_at paidAt, o.number, p.provider, p.method, p.kind, p.amount, p.refunded_amount refunded, p.status, p.payer_phone payerPhone,
                            p.provider_reference providerReference, p.provider_transaction_id transactionId, p.is_manual isManual, p.is_duplicate isDuplicate
                            FROM payments p INNER JOIN orders o ON o.id = p.order_id WHERE p.created_at BETWEEN ? AND ? ORDER BY p.created_at DESC', [$from, $to])),
                ];
            case 'newsletter':
                return [
                    'title' => 'Abonnés newsletter',
                    'columns' => [$c('email', 'E-mail', 28), $c('phone', 'Téléphone', 18), $c('consentAt', 'Consentement', 20), $c('source', 'Source')],
                    'rows' => self::rows('SELECT email, phone, consent_at consentAt, source FROM newsletter_subscribers WHERE unsubscribed_at IS NULL ORDER BY consent_at DESC'),
                ];
            case 'audit-logs':
                return [
                    'title' => "Journal d'audit",
                    'columns' => [
                        $c('createdAt', 'Date', 20), $c('adminUser', 'Utilisateur', 22), $c('action', 'Action', 24), $c('entityType', 'Entité', 16), $c('entityId', 'ID'),
                        $c('ip', 'IP', 16), $c('beforeJson', 'Avant', 40), $c('afterJson', 'Après', 40),
                    ],
                    'rows' => self::rows('SELECT l.created_at createdAt, u.full_name adminUser, l.action, l.entity_type entityType, l.entity_id entityId, l.ip, CAST(l.before AS CHAR) beforeJson, CAST(l.after AS CHAR) afterJson
                        FROM audit_logs l LEFT JOIN admin_users u ON u.id = l.admin_user_id WHERE l.created_at BETWEEN ? AND ? ORDER BY l.created_at DESC LIMIT 50000', [$from, $to]),
                ];
            default:
                throw new HttpException(400, 'Export inconnu');
        }
    }

    public function render(array $table, string $format): string
    {
        if ($format === 'csv') {
            return Csv::make(array_column($table['columns'], 'header'), array_map(
                fn ($r) => array_map(fn ($col) => $r[$col['key']] ?? null, $table['columns']), $table['rows']));
        }
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('DutCh’M');
        $ws = $book->getActiveSheet();
        $ws->setTitle(mb_substr($table['title'], 0, 31));
        $cols = $table['columns'];
        foreach ($cols as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValue("{$letter}1", $col['header']);
            $ws->getColumnDimension($letter)->setWidth($col['width']);
            if ($col['money']) {
                $ws->getStyle("{$letter}:{$letter}")->getNumberFormat()->setFormatCode('#,##0 "FCFA"');
            }
        }
        $row = 2;
        foreach ($table['rows'] as $r) {
            foreach ($cols as $i => $col) {
                $v = $r[$col['key']] ?? null;
                $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $col['money'] ? (int) $v : $v);
            }
            $row++;
        }
        $last = Coordinate::stringFromColumnIndex(count($cols));
        $header = $ws->getStyle("A1:{$last}1");
        $header->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFC9A35B');
        $ws->freezePane('A2');
        $ws->setAutoFilter("A1:{$last}1");
        $moneyCols = array_filter($cols, fn ($col) => $col['money']);
        if ($moneyCols && $table['rows']) {
            $ws->setCellValue("A{$row}", 'TOTAL');
            foreach ($cols as $i => $col) {
                if ($col['money']) {
                    $ws->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, array_sum(array_map(fn ($r) => (int) ($r[$col['key']] ?? 0), $table['rows'])));
                }
            }
            $ws->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');

        return ob_get_clean();
    }
}
