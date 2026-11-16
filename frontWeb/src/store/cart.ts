import { ItemType } from '@/shared';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export interface CartItem {
  variantId: number;
  productId: number;
  slug: string;
  name: string;
  variantName: string | null;
  imageUrl: string | null;
  unitPrice: number;
  quantity: number;
  itemType: ItemType;
  leadTimeDays: number;
  maxQuantity: number | null;
}

interface CartState {
  items: CartItem[];
  couponCode: string;
  add: (item: CartItem) => void;
  setQuantity: (variantId: number, quantity: number) => void;
  remove: (variantId: number) => void;
  replace: (items: CartItem[]) => void;
  setCoupon: (code: string) => void;
  clear: () => void;
}

export const useCartStore = create<CartState>()(
  persist(
    (set) => ({
      items: [],
      couponCode: '',
      add: (item) =>
        set((s) => {
          const existing = s.items.find((i) => i.variantId === item.variantId);
          const cap = (q: number) => (item.maxQuantity !== null ? Math.min(q, item.maxQuantity) : q);
          if (existing) {
            return { items: s.items.map((i) => (i.variantId === item.variantId ? { ...i, ...item, quantity: cap(i.quantity + item.quantity) } : i)) };
          }
          return { items: [...s.items, { ...item, quantity: cap(item.quantity) }] };
        }),
      setQuantity: (variantId, quantity) =>
        set((s) => ({ items: s.items.map((i) => (i.variantId === variantId ? { ...i, quantity: Math.max(1, quantity) } : i)) })),
      remove: (variantId) => set((s) => ({ items: s.items.filter((i) => i.variantId !== variantId) })),
      replace: (items) => set({ items }),
      setCoupon: (couponCode) => set({ couponCode }),
      clear: () => set({ items: [], couponCode: '' }),
    }),
    { name: 'amoria-cart', version: 1 },
  ),
);

export const cartCount = (items: CartItem[]) => items.reduce((s, i) => s + i.quantity, 0);
