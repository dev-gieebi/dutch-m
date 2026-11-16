export enum AvailabilityMode {
  IN_STOCK = 'IN_STOCK',
  PREORDER = 'PREORDER',
  UNAVAILABLE = 'UNAVAILABLE',
}

export enum ProductStatus {
  DRAFT = 'DRAFT',
  PUBLISHED = 'PUBLISHED',
}

export enum OrderStatus {
  PENDING_PAYMENT = 'PENDING_PAYMENT',
  CONFIRMED = 'CONFIRMED',
  IN_PROGRESS = 'IN_PROGRESS',
  PARTIALLY_DELIVERED = 'PARTIALLY_DELIVERED',
  DELIVERED = 'DELIVERED',
  CANCELLED = 'CANCELLED',
  REFUNDED = 'REFUNDED',
}

export enum PaymentStatus {
  UNPAID = 'UNPAID',
  AWAITING_CASH = 'AWAITING_CASH',
  PENDING = 'PENDING',
  DEPOSIT_PAID = 'DEPOSIT_PAID',
  PAID = 'PAID',
  FAILED = 'FAILED',
  PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED',
  REFUNDED = 'REFUNDED',
}

export enum PaymentMethod {
  CASH_ON_DELIVERY = 'CASH_ON_DELIVERY',
  AIRTEL_MONEY = 'AIRTEL_MONEY',
  MOOV_MONEY = 'MOOV_MONEY',
}

export enum PaymentProviderCode {
  AIRTEL = 'airtel',
  MOOV = 'moov',
  MOCK = 'mock',
  MANUAL = 'manual',
  CASH = 'cash',
}

export enum PaymentKind {
  DEPOSIT = 'DEPOSIT',
  BALANCE = 'BALANCE',
  FULL = 'FULL',
}

export enum PaymentRecordStatus {
  PENDING = 'PENDING',
  SUCCEEDED = 'SUCCEEDED',
  FAILED = 'FAILED',
  EXPIRED = 'EXPIRED',
  REFUNDED = 'REFUNDED',
  PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED',
}

export enum RefundStatus {
  PENDING = 'PENDING',
  SUCCEEDED = 'SUCCEEDED',
  FAILED = 'FAILED',
}

export enum ItemType {
  LOCAL = 'LOCAL',
  PREORDER = 'PREORDER',
}

export enum DeliveryMode {
  HOME = 'HOME',
  PICKUP = 'PICKUP',
}

export enum DeliveryGrouping {
  TOGETHER = 'TOGETHER',
  SPLIT = 'SPLIT',
}

export enum ShipmentStep {
  ORDER_CONFIRMED = 'ORDER_CONFIRMED',
  ORDERED_FROM_SUPPLIER = 'ORDERED_FROM_SUPPLIER',
  SHIPPED_FROM_CHINA = 'SHIPPED_FROM_CHINA',
  IN_INTERNATIONAL_TRANSIT = 'IN_INTERNATIONAL_TRANSIT',
  ARRIVED_IN_GABON = 'ARRIVED_IN_GABON',
  RECEIVED_IN_STOCK = 'RECEIVED_IN_STOCK',
  PREPARING = 'PREPARING',
  READY_FOR_PICKUP = 'READY_FOR_PICKUP',
  OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY',
  DELIVERED = 'DELIVERED',
  DELAYED = 'DELAYED',
  CANCELLED = 'CANCELLED',
  RETURNED = 'RETURNED',
}

export enum StockMovementType {
  IN = 'IN',
  OUT = 'OUT',
  RESERVE = 'RESERVE',
  RELEASE = 'RELEASE',
  ADJUST = 'ADJUST',
}

export enum OrderSource {
  WEB = 'WEB',
  ADMIN = 'ADMIN',
  WHATSAPP = 'WHATSAPP',
  PHONE = 'PHONE',
}

export enum CouponType {
  PERCENT = 'PERCENT',
  FIXED = 'FIXED',
  FREE_SHIPPING = 'FREE_SHIPPING',
}

export enum ConversationStatus {
  NEW = 'NEW',
  OPEN = 'OPEN',
  RESOLVED = 'RESOLVED',
}

export enum SenderType {
  CUSTOMER = 'CUSTOMER',
  ADMIN = 'ADMIN',
  SYSTEM = 'SYSTEM',
}

export enum NotificationChannelType {
  EMAIL = 'EMAIL',
  SMS = 'SMS',
  WHATSAPP = 'WHATSAPP',
}

export enum NotificationStatus {
  PENDING = 'PENDING',
  SENT = 'SENT',
  FAILED = 'FAILED',
}

export enum NotificationTemplateCode {
  ORDER_CREATED = 'ORDER_CREATED',
  PAYMENT_CONFIRMED = 'PAYMENT_CONFIRMED',
  PAYMENT_FAILED = 'PAYMENT_FAILED',
  SHIPMENT_STEP = 'SHIPMENT_STEP',
  SHIPMENT_DELAYED = 'SHIPMENT_DELAYED',
  OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY',
  ORDER_DELIVERED = 'ORDER_DELIVERED',
  BACK_IN_STOCK = 'BACK_IN_STOCK',
  CONVERSATION_REPLY = 'CONVERSATION_REPLY',
  PASSWORD_RESET = 'PASSWORD_RESET',
  PHONE_VERIFICATION = 'PHONE_VERIFICATION',
}

export enum AdminNotificationType {
  NEW_ORDER = 'NEW_ORDER',
  PAYMENT_RECEIVED = 'PAYMENT_RECEIVED',
  NEW_MESSAGE = 'NEW_MESSAGE',
  LOW_STOCK = 'LOW_STOCK',
  ARRIVAL_DELAYED = 'ARRIVAL_DELAYED',
  DUPLICATE_PAYMENT = 'DUPLICATE_PAYMENT',
  MISSING_ITEMS = 'MISSING_ITEMS',
}

export enum ReviewStatus {
  PENDING = 'PENDING',
  APPROVED = 'APPROVED',
  REJECTED = 'REJECTED',
}

export enum AttributeType {
  COLOR = 'COLOR',
  MATERIAL = 'MATERIAL',
  SIZE = 'SIZE',
  OTHER = 'OTHER',
}

export enum SubjectType {
  ADMIN = 'ADMIN',
  CUSTOMER = 'CUSTOMER',
}

export enum TokenPurpose {
  RESET_PASSWORD = 'RESET_PASSWORD',
  VERIFY_PHONE = 'VERIFY_PHONE',
}

export enum RoleCode {
  SUPER_ADMIN = 'SUPER_ADMIN',
  GERANT = 'GERANT',
  CATALOGUE = 'CATALOGUE',
  SERVICE_CLIENT = 'SERVICE_CLIENT',
  LOGISTIQUE = 'LOGISTIQUE',
  LIVREUR = 'LIVREUR',
}

export enum Permission {
  DASHBOARD_READ = 'dashboard.read',
  CATALOG_READ = 'catalog.read',
  CATALOG_WRITE = 'catalog.write',
  PRICES_WRITE = 'prices.write',
  STOCK_READ = 'stock.read',
  STOCK_WRITE = 'stock.write',
  SUPPLIERS_WRITE = 'suppliers.write',
  ORDERS_READ = 'orders.read',
  ORDERS_STATUS = 'orders.status',
  ORDERS_WRITE = 'orders.write',
  ORDERS_REFUND = 'orders.refund',
  SHIPMENTS_WRITE = 'shipments.write',
  ARRIVALS_WRITE = 'arrivals.write',
  DELIVERY_WRITE = 'delivery.write',
  DRIVER_DELIVERIES = 'driver.deliveries',
  PAYMENTS_READ = 'payments.read',
  PAYMENTS_MANUAL = 'payments.manual',
  CUSTOMERS_READ = 'customers.read',
  CUSTOMERS_WRITE = 'customers.write',
  MESSAGES_READ = 'messages.read',
  MESSAGES_WRITE = 'messages.write',
  MARKETING_WRITE = 'marketing.write',
  CONTENT_WRITE = 'content.write',
  REVIEWS_WRITE = 'reviews.write',
  REPORTS_READ = 'reports.read',
  EXPORTS = 'exports',
  SETTINGS_WRITE = 'settings.write',
  USERS_WRITE = 'users.write',
  AUDIT_READ = 'audit.read',
}
