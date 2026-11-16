import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { API_URL } from '@/lib/api';

/**
 * Temps réel via Laravel Reverb (protocole Pusher) avec Laravel Echo.
 * Expose une petite interface `on / off` proche de Socket.IO pour les pages.
 * Si VITE_REVERB_APP_KEY n'est pas défini, le temps réel est désactivé :
 * les écrans se rabattent sur leur rafraîchissement périodique.
 */

type Handler = (payload: never) => void;

export interface RealtimeChannel {
  on(event: string, handler: Handler): void;
  off(event: string, handler?: Handler): void;
  /** Conservé pour compatibilité : aucun message n'est envoyé au serveur. */
  emit(event: string, payload?: unknown): void;
  disconnect(): void;
}

const env = import.meta.env;
const key = env.VITE_REVERB_APP_KEY as string | undefined;

export const realtimeEnabled = !!key;

/** Crée un client Echo ; `getToken` fournit le JWT administrateur pour les canaux privés. */
export function createEcho(getToken?: () => string | null): Echo<'reverb'> | null {
  if (!key) return null;
  const scheme = (env.VITE_REVERB_SCHEME as string | undefined) ?? 'http';
  const port = Number(env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 8080));
  return new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: (env.VITE_REVERB_HOST as string | undefined) || window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    Pusher,
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        fetch(`${API_URL}/api/v1/broadcasting/auth`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(getToken?.() ? { Authorization: `Bearer ${getToken()}` } : {}),
          },
          body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
        })
          .then(async (res) => (res.ok ? callback(null, await res.json()) : callback(new Error(`Autorisation refusée (${res.status})`), null)))
          .catch((e: Error) => callback(e, null));
      },
    }),
  });
}

/** Regroupe plusieurs canaux Echo derrière l'interface on / off. */
export function channelGroup(echo: Echo<'reverb'> | null, channels: { name: string; private?: boolean }[]): RealtimeChannel {
  const handlers = new Map<string, Set<Handler>>();
  const subs = echo ? channels.map((c) => (c.private ? echo.private(c.name) : echo.channel(c.name))) : [];
  const listenerFor = new Map<Handler, (payload: never) => void>();

  return {
    on(event, handler) {
      const set = handlers.get(event) ?? new Set<Handler>();
      set.add(handler);
      handlers.set(event, set);
      const listener = (payload: never) => handler(payload);
      listenerFor.set(handler, listener);
      subs.forEach((s) => s.listen(`.${event}`, listener));
    },
    off(event, handler) {
      const targets = handler ? [handler] : [...(handlers.get(event) ?? [])];
      targets.forEach((h) => {
        const listener = listenerFor.get(h);
        subs.forEach((s) => s.stopListening(`.${event}`, listener));
        handlers.get(event)?.delete(h);
        listenerFor.delete(h);
      });
    },
    emit() {},
    disconnect() {
      channels.forEach((c) => echo?.leave(c.name));
      echo?.disconnect();
    },
  };
}

/** Suivi de l'état de connexion WebSocket (pour basculer sur le rafraîchissement périodique). */
export function onConnectionChange(echo: Echo<'reverb'> | null, cb: (connected: boolean) => void): () => void {
  const pusher = (echo?.connector as { pusher?: Pusher } | undefined)?.pusher;
  if (!pusher) {
    cb(false);
    return () => {};
  }
  const handler = ({ current }: { current: string }) => cb(current === 'connected');
  pusher.connection.bind('state_change', handler);
  cb(pusher.connection.state === 'connected');
  return () => pusher.connection.unbind('state_change', handler);
}
