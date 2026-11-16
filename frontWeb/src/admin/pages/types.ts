import { PaymentMethod, ShipmentStep } from '@/shared';

export interface AdminUser {
  id: number;
  fullName: string;
  email: string;
  phone: string | null;
  roles: { code: string; name: string }[];
  isActive: boolean;
  lastLoginAt: string | null;
  lockedUntil: string | null;
  twoFactorEnabled: boolean;
  createdAt: string;
}

export interface DriverOption {
  id: number;
  fullName: string;
  phone: string | null;
}

export interface DeliveryRow {
  shipmentId: number;
  type: string;
  currentStep: ShipmentStep | null;
  currentStepLabel: string | null;
  scheduledFor: string | null;
  deliveredAt: string | null;
  orderId: number;
  orderNumber: string;
  customerName: string;
  phone: string;
  city: string | null;
  zone: string | null;
  district: string | null;
  landmark: string | null;
  instructions: string | null;
  deliveryMode: string;
  paymentMethod: string;
  amountToCollect: number;
  items: { name: string; variantName: string | null; quantity: number }[];
  mapsUrl: string;
}

export interface PlanningGroup {
  driverId: number | null;
  driverName: string;
  deliveries: DeliveryRow[];
}

export const deliveryAddress = (d: DeliveryRow) => [d.landmark, d.district, d.zone, d.city].filter(Boolean).join(', ');
export const deliveryItemsSummary = (d: DeliveryRow) => d.items.map((i) => `${i.quantity} × ${i.name}${i.variantName ? ` (${i.variantName})` : ''}`).join(', ');

export interface PaymentAdminRow {
  id: number;
  orderId: number;
  orderNumber: string;
  provider: string;
  method: PaymentMethod;
  amount: number;
  kind: string;
  status: string;
  payerPhone: string | null;
  providerReference: string;
  providerTransactionId: string | null;
  paidAt: string | null;
  createdAt: string;
  isManual: boolean;
  isDuplicate: boolean;
  refundedAmount: number;
}
