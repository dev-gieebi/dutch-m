<?php

namespace App\Support;

use App\Enums\DeliveryMode;
use App\Enums\ItemType;
use App\Enums\Permission as P;
use App\Enums\RoleCode;
use App\Enums\ShipmentStep as S;

/** Constantes métier (rôles, permissions, étapes de suivi, libellés). */
final class Domain
{
    public const TIMEZONE = 'Africa/Libreville';

    public const CURRENCY = 'XAF';

    public const DEFAULT_LEAD_TIME_DAYS = 14;

    /** Rôles qui exigent la double authentification (vide = 2FA facultative). */
    public const ROLES_REQUIRING_2FA = [];

    public const PERMISSION_LABELS = [
        'dashboard.read' => 'Voir le tableau de bord',
        'catalog.read' => 'Consulter le catalogue',
        'catalog.write' => 'Modifier le catalogue',
        'prices.write' => 'Modifier les prix',
        'stock.read' => 'Consulter les stocks',
        'stock.write' => 'Ajuster les stocks',
        'suppliers.write' => 'Gérer les fournisseurs',
        'orders.read' => 'Consulter les commandes',
        'orders.status' => 'Changer le statut de base des commandes',
        'orders.write' => 'Gérer les commandes (création, annulation)',
        'orders.refund' => 'Rembourser',
        'shipments.write' => 'Enregistrer les étapes de suivi',
        'arrivals.write' => 'Gérer les arrivages',
        'delivery.write' => 'Gérer les livraisons et livreurs',
        'driver.deliveries' => 'Effectuer ses livraisons',
        'payments.read' => 'Consulter les paiements',
        'payments.manual' => 'Marquer un paiement comme reçu manuellement',
        'customers.read' => 'Consulter les clients',
        'customers.write' => 'Modifier les clients',
        'messages.read' => 'Lire la messagerie',
        'messages.write' => 'Répondre dans la messagerie',
        'marketing.write' => 'Gérer le marketing',
        'content.write' => 'Gérer les contenus',
        'reviews.write' => 'Modérer les avis',
        'reports.read' => 'Consulter les rapports',
        'exports' => 'Exporter les données',
        'settings.write' => 'Modifier les paramètres',
        'users.write' => 'Gérer les utilisateurs',
        'audit.read' => "Consulter le journal d'audit",
    ];

    /** @return array<string, array{name: string, description: string, permissions: list<string>}> */
    public static function roleDefinitions(): array
    {
        $all = P::values();
        $v = fn (array $perms) => array_map(fn (P $p) => $p->value, $perms);

        return [
            RoleCode::SUPER_ADMIN->value => [
                'name' => 'Super administrateur',
                'description' => 'Tous les droits',
                'permissions' => $all,
            ],
            RoleCode::GERANT->value => [
                'name' => 'Gérant',
                'description' => "Tous les droits sauf la gestion des utilisateurs et le journal d'audit",
                'permissions' => array_values(array_filter($all, fn ($p) => ! in_array($p, [P::USERS_WRITE->value, P::AUDIT_READ->value], true))),
            ],
            RoleCode::CATALOGUE->value => [
                'name' => 'Catalogue',
                'description' => 'Produits, catégories, collections, stocks, fournisseurs, promotions',
                'permissions' => $v([P::DASHBOARD_READ, P::CATALOG_READ, P::CATALOG_WRITE, P::PRICES_WRITE, P::STOCK_READ, P::STOCK_WRITE,
                    P::SUPPLIERS_WRITE, P::MARKETING_WRITE, P::REVIEWS_WRITE]),
            ],
            RoleCode::SERVICE_CLIENT->value => [
                'name' => 'Service client',
                'description' => 'Commandes (lecture + statut de base), clients, messagerie',
                'permissions' => $v([P::DASHBOARD_READ, P::CATALOG_READ, P::STOCK_READ, P::ORDERS_READ, P::ORDERS_STATUS, P::CUSTOMERS_READ,
                    P::CUSTOMERS_WRITE, P::MESSAGES_READ, P::MESSAGES_WRITE, P::PAYMENTS_READ]),
            ],
            RoleCode::LOGISTIQUE->value => [
                'name' => 'Logistique',
                'description' => 'Arrivages, étapes de suivi, préparation, affectation des livreurs',
                'permissions' => $v([P::DASHBOARD_READ, P::CATALOG_READ, P::STOCK_READ, P::ORDERS_READ, P::SHIPMENTS_WRITE, P::ARRIVALS_WRITE,
                    P::DELIVERY_WRITE, P::SUPPLIERS_WRITE]),
            ],
            RoleCode::LIVREUR->value => [
                'name' => 'Livreur',
                'description' => 'Ses livraisons uniquement',
                'permissions' => $v([P::DRIVER_DELIVERIES]),
            ],
        ];
    }

    /** @param  list<string>  $roles @return list<string> */
    public static function permissionsFor(array $roles): array
    {
        $defs = self::roleDefinitions();
        $perms = [];
        foreach ($roles as $r) {
            foreach ($defs[$r]['permissions'] ?? [] as $p) {
                $perms[$p] = true;
            }
        }

        return array_keys($perms);
    }

    public const PREORDER_STEPS = [
        'ORDER_CONFIRMED', 'ORDERED_FROM_SUPPLIER', 'SHIPPED_FROM_CHINA', 'IN_INTERNATIONAL_TRANSIT',
        'ARRIVED_IN_GABON', 'RECEIVED_IN_STOCK', 'OUT_FOR_DELIVERY', 'DELIVERED',
    ];

    /** Étapes 2 à 6 : celles portées par un arrivage. */
    public const ARRIVAL_STEPS = ['ORDERED_FROM_SUPPLIER', 'SHIPPED_FROM_CHINA', 'IN_INTERNATIONAL_TRANSIT', 'ARRIVED_IN_GABON', 'RECEIVED_IN_STOCK'];

    public const TRANSVERSE_STEPS = ['DELAYED', 'CANCELLED', 'RETURNED'];

    /** @return list<string> */
    public static function stepSequence(string $type, string $deliveryMode): array
    {
        $lastMile = $deliveryMode === DeliveryMode::PICKUP->value ? S::READY_FOR_PICKUP->value : S::OUT_FOR_DELIVERY->value;
        if ($type === ItemType::PREORDER->value) {
            return array_map(fn ($s) => $s === S::OUT_FOR_DELIVERY->value ? $lastMile : $s, self::PREORDER_STEPS);
        }

        return [S::ORDER_CONFIRMED->value, S::PREPARING->value, $lastMile, S::DELIVERED->value];
    }

    public const STEP_LABELS = [
        'ORDER_CONFIRMED' => 'Commande confirmée',
        'ORDERED_FROM_SUPPLIER' => 'Commandé au fournisseur',
        'SHIPPED_FROM_CHINA' => 'Expédié de Chine',
        'IN_INTERNATIONAL_TRANSIT' => 'En transit international',
        'ARRIVED_IN_GABON' => 'Arrivé au Gabon',
        'RECEIVED_IN_STOCK' => 'Réceptionné au stock DutCh’M',
        'PREPARING' => 'En préparation',
        'READY_FOR_PICKUP' => 'Prêt pour le retrait',
        'OUT_FOR_DELIVERY' => 'En cours de livraison',
        'DELIVERED' => 'Livré',
        'DELAYED' => 'Retard signalé',
        'CANCELLED' => 'Annulé',
        'RETURNED' => 'Retourné',
    ];

    public const ORDER_STATUS_LABELS = [
        'PENDING_PAYMENT' => 'En attente de paiement',
        'CONFIRMED' => 'Confirmée',
        'IN_PROGRESS' => 'En cours',
        'PARTIALLY_DELIVERED' => 'Partiellement livrée',
        'DELIVERED' => 'Livrée',
        'CANCELLED' => 'Annulée',
        'REFUNDED' => 'Remboursée',
    ];

    public const PAYMENT_STATUS_LABELS = [
        'UNPAID' => 'Non payée',
        'AWAITING_CASH' => "En attente d'encaissement",
        'PENDING' => 'Paiement en cours',
        'DEPOSIT_PAID' => 'Acompte payé',
        'PAID' => 'Payée',
        'FAILED' => 'Paiement échoué',
        'PARTIALLY_REFUNDED' => 'Partiellement remboursée',
        'REFUNDED' => 'Remboursée',
    ];

    public const PAYMENT_METHOD_LABELS = [
        'CASH_ON_DELIVERY' => 'Paiement à la livraison',
        'AIRTEL_MONEY' => 'Airtel Money',
        'MOOV_MONEY' => 'Moov Money (MobiCash)',
    ];

    public const AVAILABILITY_LABELS = [
        'IN_STOCK' => 'En stock',
        'PREORDER' => 'Sur commande',
        'UNAVAILABLE' => 'Indisponible',
    ];

    public const CONVERSATION_STATUS_LABELS = [
        'NEW' => 'Nouveau',
        'OPEN' => 'En cours',
        'RESOLVED' => 'Résolu',
    ];

    public static function label(array $labels, mixed $value): string
    {
        $key = $value instanceof \BackedEnum ? $value->value : (string) $value;

        return $labels[$key] ?? $key;
    }
}
