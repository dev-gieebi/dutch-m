import { create } from 'zustand';

let counter = 0;

interface UiState {
  toasts: { id: number; message: string; tone: 'success' | 'error' | 'info' }[];
  notify: (message: string, tone?: 'success' | 'error' | 'info') => void;
  dismiss: (id: number) => void;
}

export const useUiStore = create<UiState>()((set) => ({
  toasts: [],
  notify: (message, tone = 'success') =>
    set((s) => {
      const id = ++counter;
      setTimeout(() => set((x) => ({ toasts: x.toasts.filter((t) => t.id !== id) })), 4000);
      return { toasts: [...s.toasts.slice(-3), { id, message, tone }] };
    }),
  dismiss: (id) => set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) })),
}));
