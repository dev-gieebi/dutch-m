import { channelGroup, createEcho, type RealtimeChannel } from '@/lib/realtime';
import { useAuthStore } from '@/admin/store/auth';

let socket: RealtimeChannel | null = null;

/** Connexion temps réel du back-office (canaux privés « admins » et « admin.{id} », JWT administrateur). */
export function getAdminSocket(): RealtimeChannel {
  if (socket) return socket;
  const echo = createEcho(() => useAuthStore.getState().accessToken);
  const userId = useAuthStore.getState().user?.id;
  socket = channelGroup(echo, [
    { name: 'admins', private: true },
    ...(userId ? [{ name: `admin.${userId}`, private: true }] : []),
  ]);
  return socket;
}

export function closeAdminSocket() {
  socket?.disconnect();
  socket = null;
}
