import type { AdminProfile, Permission } from '@/shared';
import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface AdminAuthState {
  accessToken: string | null;
  user: AdminProfile | null;
  hasSession: boolean;
  setSession: (token: string, user: AdminProfile) => void;
  setUser: (user: AdminProfile) => void;
  clear: () => void;
}

export const useAuthStore = create<AdminAuthState>()(
  persist(
    (set) => ({
      accessToken: null,
      user: null,
      hasSession: false,
      setSession: (accessToken, user) => set({ accessToken, user, hasSession: true }),
      setUser: (user) => set({ user }),
      clear: () => set({ accessToken: null, user: null, hasSession: false }),
    }),
    { name: 'amoria-admin-auth', partialize: (s) => ({ hasSession: s.hasSession, user: s.user }) },
  ),
);

export function hasPermission(user: AdminProfile | null, permission: Permission | Permission[]): boolean {
  if (!user) return false;
  const list = Array.isArray(permission) ? permission : [permission];
  return list.some((p) => user.permissions.includes(p));
}
