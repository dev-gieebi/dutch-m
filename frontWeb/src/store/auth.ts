import type { CustomerProfile } from '@/shared';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface AuthState {
  accessToken: string | null;
  customer: CustomerProfile | null;
  /** Indique qu'une session existe probablement (cookie de rafraîchissement) */
  hasSession: boolean;
  setSession: (token: string, customer: CustomerProfile) => void;
  setCustomer: (customer: CustomerProfile) => void;
  clear: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      accessToken: null,
      customer: null,
      hasSession: false,
      setSession: (accessToken, customer) => set({ accessToken, customer, hasSession: true }),
      setCustomer: (customer) => set({ customer }),
      clear: () => set({ accessToken: null, customer: null, hasSession: false }),
    }),
    { name: 'amoria-auth', partialize: (s) => ({ hasSession: s.hasSession, customer: s.customer }) },
  ),
);
