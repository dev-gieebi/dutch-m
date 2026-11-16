import type { CartValidationResult, DeliveryGrouping, DeliveryMode, DeliveryZoneDto, PublicSettings } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { useAuthStore } from '@/store/auth';
import { CartItem, useCartStore } from '@/store/cart';
import { api } from './api';

export function useDebounce<T>(value: T, delay = 300): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return v;
}

export function useSettings() {
  return useQuery({ queryKey: ['settings'], queryFn: () => api<PublicSettings>('/settings/public'), staleTime: 5 * 60_000 });
}

export function useZones() {
  return useQuery({ queryKey: ['zones'], queryFn: () => api<DeliveryZoneDto[]>('/delivery/zones'), staleTime: 10 * 60_000 });
}

export interface ValidationParams {
  zoneId?: number;
  deliveryMode?: DeliveryMode;
  grouping?: DeliveryGrouping;
  phone?: string;
}

/** Re-vérification du panier côté serveur (prix, stock, coupon, frais). */
export function useCartValidation(params: ValidationParams = {}) {
  const items = useCartStore((s) => s.items);
  const couponCode = useCartStore((s) => s.couponCode);
  return useQuery({
    queryKey: ['cart-validate', items.map((i) => [i.variantId, i.quantity, i.unitPrice]), couponCode, params],
    queryFn: () =>
      api<CartValidationResult>('/cart/validate', {
        method: 'POST',
        body: {
          items: items.map((i) => ({ variantId: i.variantId, quantity: i.quantity, expectedPrice: i.unitPrice })),
          couponCode: couponCode || undefined,
          ...params,
        },
      }),
    enabled: items.length > 0,
    staleTime: 0,
  });
}

/** Aligne le panier local sur la réponse serveur (quantités ajustées, prix à jour, articles retirés). */
export function applyValidationToCart(result: CartValidationResult) {
  const { items, replace } = useCartStore.getState();
  const next: CartItem[] = [];
  for (const item of items) {
    const line = result.lines.find((l) => l.variantId === item.variantId);
    if (!line || line.quantity === 0) continue;
    next.push({ ...item, unitPrice: line.unitPrice, quantity: line.quantity, itemType: line.itemType, maxQuantity: line.maxQuantity, leadTimeDays: line.leadTimeDays });
  }
  replace(next);
}

/** Synchronise le panier avec le compte client connecté. */
export function useCartSync() {
  const token = useAuthStore((s) => s.accessToken);
  const items = useCartStore((s) => s.items);
  const loaded = useRef<string | null>(null);

  useEffect(() => {
    if (!token || loaded.current === token) return;
    loaded.current = token;
    void (async () => {
      const remote = await api<{ items: { variantId: number; quantity: number }[] }>('/me/cart').catch(() => ({ items: [] }));
      const local = useCartStore.getState().items;
      const missing = remote.items.filter((r) => !local.some((l) => l.variantId === r.variantId));
      if (missing.length) {
        const validation = await api<CartValidationResult>('/cart/validate', { method: 'POST', body: { items: missing } }).catch(() => null);
        validation?.lines
          .filter((l) => l.quantity > 0)
          .forEach((l) =>
            useCartStore.getState().add({
              variantId: l.variantId, productId: l.productId, slug: l.productSlug, name: l.name,
              variantName: l.variantName === 'Standard' ? null : l.variantName, imageUrl: l.imageUrl, unitPrice: l.unitPrice,
              quantity: l.quantity, itemType: l.itemType, leadTimeDays: l.leadTimeDays, maxQuantity: l.maxQuantity,
            }),
          );
      }
    })();
  }, [token]);

  const debounced = useDebounce(items, 800);
  useEffect(() => {
    if (!token || loaded.current !== token) return;
    void api('/me/cart', { method: 'PUT', body: { items: debounced.map((i) => ({ variantId: i.variantId, quantity: i.quantity })) } }).catch(() => undefined);
  }, [debounced, token]);
}
