import type { AdminProfile } from '@/shared';
import { API_URL } from '@/lib/api';
import { useAuthStore } from '@/admin/store/auth';

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

function buildUrl(path: string, query?: Query) {
  const base = API_URL ? `${API_URL}/api/v1` : '/api/v1';
  const url = new URL(`${base}${path}`, window.location.origin);
  Object.entries(query ?? {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
  });
  return url.toString();
}

let refreshing: Promise<boolean> | null = null;

export function refreshSession(): Promise<boolean> {
  refreshing ??= fetch(`${BASE}/admin/auth/refresh`, { method: 'POST', credentials: 'include' })
    .then(async (res) => {
      if (!res.ok) {
        useAuthStore.getState().clear();
        return false;
      }
      const data = (await res.json()) as { accessToken: string; user: AdminProfile };
      useAuthStore.getState().setSession(data.accessToken, data.user);
      return true;
    })
    .catch(() => false)
    .finally(() => (refreshing = null));
  return refreshing;
}

export async function api<T>(path: string, opts: { method?: string; body?: unknown; query?: Query; raw?: boolean } = {}, retried = false): Promise<T> {
  const token = useAuthStore.getState().accessToken;
  const isForm = typeof FormData !== 'undefined' && opts.body instanceof FormData;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (opts.body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;
  let res: Response;
  try {
    res = await fetch(buildUrl(path, opts.query), {
      method: opts.method ?? 'GET',
      headers,
      body: opts.body === undefined ? undefined : isForm ? (opts.body as FormData) : JSON.stringify(opts.body),
      credentials: 'include',
    });
  } catch {
    throw new ApiError(0, 'Connexion impossible à l’API.');
  }
  if (res.status === 401 && token && !retried && (await refreshSession())) return api<T>(path, opts, true);
  if (!res.ok) {
    const data = (await res.json().catch(() => ({}))) as { message?: string | string[]; errors?: unknown };
    const message = Array.isArray(data.message) ? data.message.join(' · ') : data.message;
    throw new ApiError(res.status, message ?? 'Une erreur est survenue.', data.errors);
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

/** Télécharge un export (CSV/XLSX) en conservant le nom de fichier du serveur. */
export async function download(path: string, query?: Query) {
  const token = useAuthStore.getState().accessToken;
  const res = await fetch(buildUrl(path, query), { headers: token ? { Authorization: `Bearer ${token}` } : {}, credentials: 'include' });
  if (!res.ok) {
    const data = (await res.json().catch(() => ({}))) as { message?: string };
    throw new ApiError(res.status, data.message ?? 'Export impossible');
  }
  const blob = await res.blob();
  const name = res.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1] ?? 'export';
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.click();
  URL.revokeObjectURL(a.href);
}
