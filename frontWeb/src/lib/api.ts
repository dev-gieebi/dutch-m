import type { CustomerProfile } from '@/shared';
import { useAuthStore } from '@/store/auth';

/** URL de l'API ; vide = même origine (application servie par le backend Laravel). */
export const API_URL = ((import.meta.env.VITE_API_URL as string | undefined) ?? '').replace(/\/$/, '');
export const SITE_URL = ((import.meta.env.VITE_SITE_URL as string | undefined) || window.location.origin).replace(/\/$/, '');
const BASE = `${API_URL}/api/v1`;

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors?: unknown,
  ) {
    super(message);
  }
}

type Query = Record<string, string | number | boolean | undefined | null>;

interface Options {
  method?: string;
  body?: unknown;
  query?: Query;
  auth?: boolean;
  signal?: AbortSignal;
}

let refreshing: Promise<boolean> | null = null;

/** Renouvelle le jeton d'accès client via le cookie httpOnly (rotation côté serveur). */
export function refreshSession(): Promise<boolean> {
  refreshing ??= fetch(`${BASE}/auth/customer/refresh`, { method: 'POST', credentials: 'include' })
    .then(async (res) => {
      if (!res.ok) {
        useAuthStore.getState().clear();
        return false;
      }
      const data = (await res.json()) as { accessToken: string; customer: CustomerProfile };
      useAuthStore.getState().setSession(data.accessToken, data.customer);
      return true;
    })
    .catch(() => false)
    .finally(() => (refreshing = null));
  return refreshing;
}

function buildUrl(path: string, query?: Query) {
  const url = new URL(`${BASE}${path}`, window.location.origin);
  Object.entries(query ?? {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
  });
  return url.toString();
}

export async function api<T>(path: string, opts: Options = {}, retried = false): Promise<T> {
  const token = useAuthStore.getState().accessToken;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;
  let res: Response;
  try {
    res = await fetch(buildUrl(path, opts.query), {
      method: opts.method ?? 'GET',
      headers,
      body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
      credentials: 'include',
      signal: opts.signal,
    });
  } catch {
    throw new ApiError(0, 'Connexion impossible. Vérifiez votre connexion internet et réessayez.');
  }
  if (res.status === 401 && token && !retried && (await refreshSession())) return api<T>(path, opts, true);
  if (!res.ok) {
    const data = (await res.json().catch(() => ({}))) as { message?: string; errors?: unknown };
    throw new ApiError(res.status, data.message ?? 'Une erreur est survenue. Veuillez réessayer.', data.errors);
  }
  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}

export const errorMessage = (e: unknown) => (e instanceof Error ? e.message : 'Une erreur est survenue.');

/** URL absolue d'un fichier servi par l'API (/uploads/...). */
export function assetUrl(path: string | null | undefined): string {
  if (!path) return '';
  return path.startsWith('/uploads/') ? `${API_URL}${path}` : path;
}
