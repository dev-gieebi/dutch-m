import {
  AvailabilityMode,
  ConversationStatus,
  DeliveryMode,
  ItemType,
  OrderStatus,
  PaymentMethod,
  PaymentStatus,
  Permission,
  RoleCode,
  ShipmentStep,
} from './enums';

export const ALL_PERMISSIONS = Object.values(Permission);

export const PERMISSION_LABELS: Record<Permission, string> = {
  [Permission.DASHBOARD_READ]: 'Voir le tableau de bord',
  [Permission.CATALOG_READ]: 'Consulter le catalogue',
  [Permission.CATALOG_WRITE]: 'Modifier le catalogue',
  [Permission.PRICES_WRITE]: 'Modifier les prix',
  [Permission.STOCK_READ]: 'Consulter les stocks',
  [Permission.STOCK_WRITE]: 'Ajuster les stocks',
  [Permission.SUPPLIERS_WRITE]: 'Gérer les fournisseurs',
  [Permission.ORDERS_READ]: 'Consulter les commandes',
  [Permission.ORDERS_STATUS]: 'Changer le statut de base des commandes',
  [Permission.ORDERS_WRITE]: 'Gérer les commandes (création, annulation)',
  [Permission.ORDERS_REFUND]: 'Rembourser',
  [Permission.SHIPMENTS_WRITE]: 'Enregistrer les étapes de suivi',
  [Permission.ARRIVALS_WRITE]: 'Gérer les arrivages',
  [Permission.DELIVERY_WRITE]: 'Gérer les livraisons et livreurs',
  [Permission.DRIVER_DELIVERIES]: 'Effectuer ses livraisons',
  [Permission.PAYMENTS_READ]: 'Consulter les paiements',
  [Permission.PAYMENTS_MANUAL]: 'Marquer un paiement comme reçu manuellement',
  [Permission.CUSTOMERS_READ]: 'Consulter les clients',
  [Permission.CUSTOMERS_WRITE]: 'Modifier les clients',
  [Permission.MESSAGES_READ]: 'Lire la messagerie',
  [Permission.MESSAGES_WRITE]: 'Répondre dans la messagerie',
  [Permission.MARKETING_WRITE]: 'Gérer le marketing',
  [Permission.CONTENT_WRITE]: 'Gérer les contenus',
  [Permission.REVIEWS_WRITE]: 'Modérer les avis',
  [Permission.REPORTS_READ]: 'Consulter les rapports',
  [Permission.EXPORTS]: 'Exporter les données',
  [Permission.SETTINGS_WRITE]: 'Modifier les paramètres',
  [Permission.USERS_WRITE]: 'Gérer les utilisateurs',
  [Permission.AUDIT_READ]: "Consulter le journal d'audit",
};

const P = Permission;

export const ROLE_DEFINITIONS: Record<RoleCode, { name: string; description: string; permissions: Permission[] }> = {
  [RoleCode.SUPER_ADMIN]: {
    name: 'Super administrateur',
    description: 'Tous les droits',
    permissions: ALL_PERMISSIONS,
  },
  [RoleCode.GERANT]: {
    name: 'Gérant',
    description: "Tous les droits sauf la gestion des utilisateurs et le journal d'audit",
    permissions: ALL_PERMISSIONS.filter((p) => p !== P.USERS_WRITE && p !== P.AUDIT_READ),
  },
  [RoleCode.CATALOGUE]: {
    name: 'Catalogue',
    description: 'Produits, catégories, collections, stocks, fournisseurs, promotions',
    permissions: [
      P.DASHBOARD_READ, P.CATALOG_READ, P.CATALOG_WRITE, P.PRICES_WRITE, P.STOCK_READ, P.STOCK_WRITE,
      P.SUPPLIERS_WRITE, P.MARKETING_WRITE, P.REVIEWS_WRITE,
    ],
  },
  [RoleCode.SERVICE_CLIENT]: {
    name: 'Service client',
    description: 'Commandes (lecture + statut de base), clients, messagerie',
    permissions: [
      P.DASHBOARD_READ, P.CATALOG_READ, P.STOCK_READ, P.ORDERS_READ, P.ORDERS_STATUS, P.CUSTOMERS_READ,
      P.CUSTOMERS_WRITE, P.MESSAGES_READ, P.MESSAGES_WRITE, P.PAYMENTS_READ,
    ],
  },
  [RoleCode.LOGISTIQUE]: {
    name: 'Logistique',
    description: 'Arrivages, étapes de suivi, préparation, affectation des livreurs',
    permissions: [
      P.DASHBOARD_READ, P.CATALOG_READ, P.STOCK_READ, P.ORDERS_READ, P.SHIPMENTS_WRITE, P.ARRIVALS_WRITE,
      P.DELIVERY_WRITE, P.SUPPLIERS_WRITE,
    ],
  },
  [RoleCode.LIVREUR]: {
    name: 'Livreur',
    description: 'Ses livraisons uniquement',
    permissions: [P.DRIVER_DELIVERIES],
  },
};

/** Rôles qui exigent la double authentification (vide = 2FA facultative, activable depuis le profil). */
export const ROLES_REQUIRING_2FA: RoleCode[] = [];

export const PREORDER_STEPS: ShipmentStep[] = [
  ShipmentStep.ORDER_CONFIRMED,
  ShipmentStep.ORDERED_FROM_SUPPLIER,
  ShipmentStep.SHIPPED_FROM_CHINA,
  ShipmentStep.IN_INTERNATIONAL_TRANSIT,
  ShipmentStep.ARRIVED_IN_GABON,
  ShipmentStep.RECEIVED_IN_STOCK,
  ShipmentStep.OUT_FOR_DELIVERY,
  ShipmentStep.DELIVERED,
];

/** Étapes 2 à 6 : celles portées par un arrivage. */
export const ARRIVAL_STEPS: ShipmentStep[] = PREORDER_STEPS.slice(1, 6);

export const TRANSVERSE_STEPS: ShipmentStep[] = [ShipmentStep.DELAYED, ShipmentStep.CANCELLED, ShipmentStep.RETURNED];

export function getStepSequence(type: ItemType, deliveryMode: DeliveryMode): ShipmentStep[] {
  const lastMile =
    deliveryMode === DeliveryMode.PICKUP ? ShipmentStep.READY_FOR_PICKUP : ShipmentStep.OUT_FOR_DELIVERY;
  if (type === ItemType.PREORDER) {
    return PREORDER_STEPS.map((s) => (s === ShipmentStep.OUT_FOR_DELIVERY ? lastMile : s));
  }
  return [ShipmentStep.ORDER_CONFIRMED, ShipmentStep.PREPARING, lastMile, ShipmentStep.DELIVERED];
}

export const STEP_LABELS: Record<ShipmentStep, string> = {
  [ShipmentStep.ORDER_CONFIRMED]: 'Commande confirmée',
  [ShipmentStep.ORDERED_FROM_SUPPLIER]: 'Commandé au fournisseur',
  [ShipmentStep.SHIPPED_FROM_CHINA]: 'Expédié de Chine',
  [ShipmentStep.IN_INTERNATIONAL_TRANSIT]: 'En transit international',
  [ShipmentStep.ARRIVED_IN_GABON]: 'Arrivé au Gabon',
  [ShipmentStep.RECEIVED_IN_STOCK]: 'Réceptionné au stock DutCh’M',
  [ShipmentStep.PREPARING]: 'En préparation',
  [ShipmentStep.READY_FOR_PICKUP]: 'Prêt pour le retrait',
  [ShipmentStep.OUT_FOR_DELIVERY]: 'En cours de livraison',
  [ShipmentStep.DELIVERED]: 'Livré',
  [ShipmentStep.DELAYED]: 'Retard signalé',
  [ShipmentStep.CANCELLED]: 'Annulé',
  [ShipmentStep.RETURNED]: 'Retourné',
};

export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
  [OrderStatus.PENDING_PAYMENT]: 'En attente de paiement',
  [OrderStatus.CONFIRMED]: 'Confirmée',
  [OrderStatus.IN_PROGRESS]: 'En cours',
  [OrderStatus.PARTIALLY_DELIVERED]: 'Partiellement livrée',
  [OrderStatus.DELIVERED]: 'Livrée',
  [OrderStatus.CANCELLED]: 'Annulée',
  [OrderStatus.REFUNDED]: 'Remboursée',
};

export const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
  [PaymentStatus.UNPAID]: 'Non payée',
  [PaymentStatus.AWAITING_CASH]: "En attente d'encaissement",
  [PaymentStatus.PENDING]: 'Paiement en cours',
  [PaymentStatus.DEPOSIT_PAID]: 'Acompte payé',
  [PaymentStatus.PAID]: 'Payée',
  [PaymentStatus.FAILED]: 'Paiement échoué',
  [PaymentStatus.PARTIALLY_REFUNDED]: 'Partiellement remboursée',
  [PaymentStatus.REFUNDED]: 'Remboursée',
};

export const PAYMENT_METHOD_LABELS: Record<PaymentMethod, string> = {
  [PaymentMethod.CASH_ON_DELIVERY]: 'Paiement à la livraison',
  [PaymentMethod.AIRTEL_MONEY]: 'Airtel Money',
  [PaymentMethod.MOOV_MONEY]: 'Moov Money (MobiCash)',
};

export const AVAILABILITY_LABELS: Record<AvailabilityMode, string> = {
  [AvailabilityMode.IN_STOCK]: 'En stock',
  [AvailabilityMode.PREORDER]: 'Sur commande',
  [AvailabilityMode.UNAVAILABLE]: 'Indisponible',
};

export const CONVERSATION_STATUS_LABELS: Record<ConversationStatus, string> = {
  [ConversationStatus.NEW]: 'Nouveau',
  [ConversationStatus.OPEN]: 'En cours',
  [ConversationStatus.RESOLVED]: 'Résolu',
};

export const TIMEZONE = 'Africa/Libreville';
export const CURRENCY = 'XAF';
export const DEFAULT_LEAD_TIME_DAYS = 14;
