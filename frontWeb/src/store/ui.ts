import { create } from 'zustand';
import { persist } from 'zustand/middleware';

export interface ChatContext {
  productId?: number;
  productName?: string;
  productReference?: string;
  orderNumber?: string;
  message?: string;
}

interface UiState {
  chatOpen: boolean;
  chatContext: ChatContext | null;
  conversationToken: string | null;
  cookieConsent: 'necessary' | 'all' | null;
  toast: { message: string; tone: 'success' | 'error' } | null;
  openChat: (ctx?: ChatContext) => void;
  closeChat: () => void;
  setConversationToken: (token: string | null) => void;
  setCookieConsent: (v: 'necessary' | 'all') => void;
  notify: (message: string, tone?: 'success' | 'error') => void;
  dismissToast: () => void;
}

export const useUiStore = create<UiState>()(
  persist(
    (set) => ({
      chatOpen: false,
      chatContext: null,
      conversationToken: null,
      cookieConsent: null,
      toast: null,
      openChat: (ctx) =>
        set((s) => ({
          chatOpen: true,
          chatContext: ctx ?? null,
          conversationToken: ctx?.productId || ctx?.orderNumber ? null : s.conversationToken,
        })),
      closeChat: () => set({ chatOpen: false }),
      setConversationToken: (conversationToken) => set({ conversationToken }),
      setCookieConsent: (cookieConsent) => set({ cookieConsent }),
      notify: (message, tone = 'success') => set({ toast: { message, tone } }),
      dismissToast: () => set({ toast: null }),
    }),
    { name: 'amoria-ui', partialize: (s) => ({ conversationToken: s.conversationToken, cookieConsent: s.cookieConsent }) },
  ),
);
