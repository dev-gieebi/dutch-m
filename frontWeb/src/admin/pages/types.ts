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

export interface PlanningShipment {
  id: number;
  orderId: number;
  orderNumber: string;
  contactName: string;
  contactPhone: string;
  address: string;
  currentStep: ShipmentStep | null;
  type: string;
  scheduledFor: string | null;
  deliveredAt: string | null;
  deliveryCode: string;
}

export interface Planning {
  date: string;
  drivers: { driver: DriverOption | null; shipments: PlanningShipment[] }[];
}

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
