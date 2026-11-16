import { buildWhatsAppLink, ConversationDto, MessageDto, SenderType, startConversationSchema } from '@/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Send, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { API_URL, api, errorMessage } from '@/lib/api';
import { channelGroup, createEcho, onConnectionChange } from '@/lib/realtime';
import { useSettings } from '@/lib/hooks';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Alert, Spinner } from './ui';

type StartForm = z.input<typeof startConversationSchema>;

function StartConversation() {
  const ctx = useUiStore((s) => s.chatContext);
  const setToken = useUiStore((s) => s.setConversationToken);
  const customer = useAuthStore((s) => s.customer);
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, formState } = useForm<StartForm>({
    resolver: zodResolver(startConversationSchema),
    defaultValues: {
      name: customer?.fullName ?? '',
      phone: customer?.phone ?? '',
      message: ctx?.message ?? (ctx?.productName ? `Bonjour, j’ai une question sur « ${ctx.productName} » (réf. ${ctx.productReference}).` : ''),
      productId: ctx?.productId,
      orderNumber: ctx?.orderNumber,
    },
  });
  const onSubmit = handleSubmit(async (values) => {
    setError(null);
    try {
      const conv = await api<ConversationDto>('/conversations', { method: 'POST', body: startConversationSchema.parse(values) });
      setToken(conv.publicToken);
    } catch (e) {
      setError(errorMessage(e));
    }
  });
  return (
    <form onSubmit={onSubmit} className="space-y-3 p-4" noValidate>
      {ctx?.productName && <p className="rounded-lg bg-sand px-3 py-2 text-xs">Question sur : <strong>{ctx.productName}</strong> · réf. {ctx.productReference}</p>}
      <div>
        <input className="input" placeholder="Votre nom" {...register('name')} aria-label="Votre nom" />
        {formState.errors.name && <p className="field-error">{formState.errors.name.message}</p>}
      </div>
      <div>
        <input className="input" placeholder="Téléphone (+241…)" inputMode="tel" {...register('phone')} aria-label="Téléphone" />
        {formState.errors.phone && <p className="field-error">{formState.errors.phone.message}</p>}
      </div>
      <div>
        <textarea className="input min-h-[96px]" placeholder="Votre message" {...register('message')} aria-label="Message" />
        {formState.errors.message && <p className="field-error">{formState.errors.message.message}</p>}
      </div>
      {error && <Alert tone="error">{error}</Alert>}
      <button className="btn-primary w-full" disabled={formState.isSubmitting}>{formState.isSubmitting ? <Spinner /> : 'Démarrer la discussion'}</button>
    </form>
  );
}

function Conversation({ token }: { token: string }) {
  const qc = useQueryClient();
  const setToken = useUiStore((s) => s.setConversationToken);
  const [body, setBody] = useState('');
  const [sending, setSending] = useState(false);
  const [connected, setConnected] = useState(false);
  const endRef = useRef<HTMLDivElement>(null);
  const { data, isError } = useQuery({
    queryKey: ['conversation', token],
    queryFn: () => api<ConversationDto>(`/conversations/${token}`),
    refetchInterval: connected ? false : 10_000,
  });

  useEffect(() => {
    // Canal public « chat.{jeton} » : le jeton secret de la conversation fait office d'autorisation.
    const echo = createEcho();
    const socket = channelGroup(echo, [{ name: `chat.${token}` }]);
    const unbind = onConnectionChange(echo, setConnected);
    socket.on('message.created', (m: MessageDto) =>
      qc.setQueryData<ConversationDto>(['conversation', token], (old) => (old && !old.messages.some((x) => x.id === m.id) ? { ...old, messages: [...old.messages, m] } : old)),
    );
    return () => {
      unbind();
      socket.disconnect();
    };
  }, [token, qc]);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [data?.messages.length]);
  useEffect(() => {
    if (isError) setToken(null);
  }, [isError, setToken]);

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!body.trim()) return;
    setSending(true);
    try {
      const m = await api<MessageDto>(`/conversations/${token}/messages`, { method: 'POST', body: { body } });
      qc.setQueryData<ConversationDto>(['conversation', token], (old) => (old && !old.messages.some((x) => x.id === m.id) ? { ...old, messages: [...old.messages, m] } : old));
      setBody('');
    } finally {
      setSending(false);
    }
  };

  if (!data) return <div className="flex justify-center p-8"><Spinner /></div>;
  return (
    <div className="flex h-full flex-col">
      {(data.product || data.orderNumber) && (
        <p className="border-b border-sand bg-sand/60 px-4 py-2 text-xs">
          {data.product && <>Produit : <strong>{data.product.name}</strong> · {data.product.reference}</>}
          {data.orderNumber && <> Commande : <strong>{data.orderNumber}</strong></>}
        </p>
      )}
      <div className="flex-1 space-y-2 overflow-y-auto p-4" aria-live="polite">
        {data.messages.map((m) => (
          <div key={m.id} className={`flex ${m.senderType === SenderType.CUSTOMER ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[85%] whitespace-pre-line rounded-2xl px-3 py-2 text-sm ${m.senderType === SenderType.CUSTOMER ? 'bg-gold-gradient text-white' : m.senderType === SenderType.SYSTEM ? 'bg-sand text-ink-soft' : 'bg-white text-ink shadow-soft'}`}>
              {m.senderName && m.senderType !== SenderType.CUSTOMER && <p className="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-gold-dark">{m.senderName}</p>}
              {m.body}
              {m.attachmentUrl && <img src={m.attachmentUrl.startsWith('/uploads') ? `${API_URL}${m.attachmentUrl}` : m.attachmentUrl} alt="Pièce jointe" className="mt-2 max-h-48 rounded-lg" loading="lazy" />}
              <p className="mt-1 text-right text-[10px] opacity-70">{new Date(m.createdAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Libreville' })}</p>
            </div>
          </div>
        ))}
        <div ref={endRef} />
      </div>
      <form onSubmit={send} className="flex gap-2 border-t border-sand p-3">
        <label htmlFor="chat-input" className="sr-only">Votre message</label>
        <input id="chat-input" className="input" value={body} onChange={(e) => setBody(e.target.value)} placeholder="Écrire un message…" maxLength={3000} />
        <button className="btn-primary !px-3" disabled={sending || !body.trim()} aria-label="Envoyer">{sending ? <Spinner /> : <Send className="h-4 w-4" />}</button>
      </form>
    </div>
  );
}

export function ChatWidget() {
  const open = useUiStore((s) => s.chatOpen);
  const close = useUiStore((s) => s.closeChat);
  const token = useUiStore((s) => s.conversationToken);
  const ctx = useUiStore((s) => s.chatContext);
  const setToken = useUiStore((s) => s.setConversationToken);
  const { data: settings } = useSettings();

  useEffect(() => {
    const t = new URLSearchParams(window.location.search).get('chat');
    if (t && t.length > 10) {
      setToken(t);
      useUiStore.getState().openChat();
    }
  }, [setToken]);

  if (!open) return null;
  const waMessage = ctx?.productName
    ? `Bonjour DutCh’M, j'ai une question sur le produit ${ctx.productName} – réf. ${ctx.productReference}`
    : 'Bonjour DutCh’M, j’ai une question.';
  const showStart = !token;
  return (
    <section className="fixed inset-x-0 bottom-0 z-50 flex h-[85vh] flex-col overflow-hidden rounded-t-2xl bg-ivory shadow-soft animate-rise sm:inset-x-auto sm:bottom-6 sm:right-6 sm:h-[560px] sm:w-[380px] sm:rounded-2xl" aria-label="Messagerie DutCh’M">
      <header className="flex items-center justify-between bg-gold-gradient px-4 py-3 text-white">
        <div>
          <p className="font-serif text-lg font-bold tracking-widest">DutCh’M</p>
          <p className="text-xs">{settings?.supportOnline ? 'En ligne · réponse rapide' : `Hors ligne · ${settings?.supportHours.label ?? ''}`}</p>
        </div>
        <button onClick={close} className="rounded-full p-2 hover:bg-white/20" aria-label="Fermer la messagerie"><X className="h-5 w-5" /></button>
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto">{showStart ? <StartConversation /> : <Conversation token={token!} />}</div>
      <footer className="flex items-center justify-between gap-2 border-t border-sand px-4 py-2 text-xs">
        {token && <button className="text-ink-soft underline" onClick={() => setToken(null)}>Nouvelle discussion</button>}
        {settings && (
          <a href={buildWhatsAppLink(settings.whatsappNumber, waMessage)} target="_blank" rel="noopener noreferrer" className="ml-auto font-semibold text-[#128C7E]">
            Continuer sur WhatsApp →
          </a>
        )}
      </footer>
    </section>
  );
}
