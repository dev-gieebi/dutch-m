import type {
  AvailabilityMode,
  ConversationStatus,
  DeliveryGrouping,
  DeliveryMode,
  ItemType,
  OrderStatus,
  PaymentMethod,
  PaymentRecordStatus,
  PaymentStatus,
  SenderType,
  ShipmentStep,
} from './enums';

export interface PaginationMeta {
  page: number;
  limit: number;
  total: number;
}

export interface Paginated<T> {
  data: T[];
  meta: PaginationMeta;
}

export interface ApiError {
  statusCode: number;
  message: string;
  errors?: Record<string, string[]> | string[];
}

export interface ImageDto {
  id: number;
  url: string;
  mediumUrl: string;
  thumbUrl: string;
  alt: string;
  isMain: boolean;
  variantId: number | null;
}

export interface VariantAttributeDto {
  attribute: string;
  type: string;
  value: string;
  hexColor: string | null;
}

export interface VariantDto {
  id: number;
  sku: string;
  name: string;
  price: number;
  compareAtPrice: number | null;
  availability: AvailabilityMode;
  /** Stock disponible (uniquement pour les variantes en stock) */
  stockAvailable: number | null;
  /** Quantité maximale commandable (null = illimitée) */
  maxQuantity: number | null;
  leadTimeDays: number;
  attributes: VariantAttributeDto[];
  imageIds: number[];
  isDefault: boolean;
}

export interface CategoryRef {
  id: number;
  name: string;
  slug: string;
}

export interface ProductCardDto {
  id: number;
  slug: string;
  name: string;
  reference: string;
  price: number;
  compareAtPrice: number | null;
  availability: AvailabilityMode;
  leadTimeDays: number;
  image: ImageDto | null;
  isNew: boolean;
  isBestSeller: boolean;
  onSale: boolean;
  category: CategoryRef | null;
  defaultVariantId: number | null;
}

export interface ProductDetailDto extends ProductCardDto {
  shortDescription: string | null;
  description: string | null;
  dimensions: string | null;
  material: string | null;
  weight: number | null;
  careInstructions: string | null;
  colors: string[];
  images: ImageDto[];
  variants: VariantDto[];
  seoTitle: string | null;
  seoDescription: string | null;
  depositPercent: number;
  fullPaymentRequired: boolean;
  breadcrumbs: CategoryRef[];
}

export interface CategoryDto {
  id: number;
  parentId: number | null;
  name: string;
  slug: string;
  code: string;
  description: string | null;
  imageUrl: string | null;
  seoTitle?: string | null;
  seoDescription?: string | null;
  children?: CategoryDto[];
}

export interface CollectionDto {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  imageUrl: string | null;
  startsAt: string | null;
  endsAt: string | null;
  isFeatured: boolean;
}

export interface FacetsDto {
  colors: { value: string; hexColor: string | null }[];
  materials: string[];
  priceRange: { min: number; max: number };
}

export interface DeliveryZoneDto {
  id: number;
  city: string;
  name: string;
  fee: number;
  estimatedDelay: string;
}

export interface CartLineInput {
  variantId: number;
  quantity: number;
}

export interface CartValidationLine {
  variantId: number;
  productId: number;
  productSlug: string;
  name: string;
  variantName: string;
  sku: string;
  imageUrl: string | null;
  unitPrice: number;
  compareAtPrice: number | null;
  quantity: number;
  requestedQuantity: number;
  lineTotal: number;
  itemType: ItemType;
  availability: AvailabilityMode;
  leadTimeDays: number;
  maxQuantity: number | null;
  depositAmount: number;
  issues: string[];
}

export interface CartTotals {
  subtotal: number;
  localSubtotal: number;
  preorderSubtotal: number;
  discountTotal: number;
  shippingTotal: number;
  shipmentsCount: number;
  total: number;
  depositAmount: number;
  /** Montant à payer maintenant si paiement mobile money */
  dueNow: number;
  balanceAfter: number;
  freeShipping: boolean;
}

export interface CartValidationResult {
  lines: CartValidationLine[];
  totals: CartTotals;
  coupon: { code: string; description: string | null; discount: number; freeShipping: boolean } | null;
  couponError: string | null;
  issues: string[];
  hasLocal: boolean;
  hasPreorder: boolean;
  preorderLeadTimeDays: number;
  estimatedPreorderDate: string | null;
  depositPercent: number;
  fullPaymentRequired: boolean;
  allowedPaymentMethods: PaymentMethod[];
}

export interface TrackingEventDto {
  step: ShipmentStep;
  label: string;
  occurredAt: string;
  publicComment: string | null;
  proofUrl: string | null;
}

export interface TrackingShipmentDto {
  id: number;
  type: ItemType;
  currentStep: ShipmentStep | null;
  steps: { step: ShipmentStep; label: string }[];
  estimatedDeliveryDate: string | null;
  isDelayed: boolean;
  delayReason: string | null;
  shippingFee: number;
  events: TrackingEventDto[];
  items: { name: string; variantName: string | null; quantity: number; imageUrl: string | null }[];
}

export interface OrderTrackingDto {
  number: string;
  status: OrderStatus;
  paymentStatus: PaymentStatus;
  paymentMethod: PaymentMethod;
  deliveryMode: DeliveryMode;
  deliveryGrouping: DeliveryGrouping;
  createdAt: string;
  customerFirstName: string;
  subtotal: number;
  discountTotal: number;
  shippingTotal: number;
  total: number;
  depositAmount: number;
  amountPaid: number;
  balanceDue: number;
  shipments: TrackingShipmentDto[];
  pendingPaymentId: number | null;
}

export interface CreateOrderResponse {
  orderId: number;
  number: string;
  trackingToken: string;
  status: OrderStatus;
  paymentStatus: PaymentStatus;
  total: number;
  dueNow: number;
}

export interface PaymentStatusDto {
  id: number;
  status: PaymentRecordStatus;
  amount: number;
  orderNumber: string;
  orderTrackingToken: string;
  instructions: string | null;
  isMock: boolean;
  failureReason: string | null;
}

export interface MessageDto {
  id: number;
  senderType: SenderType;
  senderName: string | null;
  body: string;
  attachmentUrl: string | null;
  createdAt: string;
}

export interface ConversationDto {
  id: number;
  publicToken: string;
  status: ConversationStatus;
  visitorName: string;
  product: { id: number; name: string; reference: string; slug: string } | null;
  orderNumber: string | null;
  messages: MessageDto[];
}

export interface BannerDto {
  id: number;
  title: string;
  subtitle: string | null;
  imageDesktopUrl: string;
  imageMobileUrl: string | null;
  linkUrl: string | null;
  ctaLabel: string | null;
}

export interface PageDto {
  slug: string;
  title: string;
  content: string;
  seoTitle: string | null;
  seoDescription: string | null;
}

export interface FaqCategoryDto {
  id: number;
  name: string;
  faqs: { id: number; question: string; answer: string }[];
}

export interface AdminProfile {
  id: number;
  email: string;
  fullName: string;
  roles: string[];
  permissions: string[];
  twoFactorEnabled: boolean;
}

export interface CustomerProfile {
  id: number;
  fullName: string;
  phone: string;
  email: string | null;
  phoneVerified: boolean;
  newsletterConsent: boolean;
}
