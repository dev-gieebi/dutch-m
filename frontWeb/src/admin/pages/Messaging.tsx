import {
  ConversationStatus,
  CONVERSATION_STATUS_LABELS,
  formatDate,
  formatFCFA,
  formatPhone,
  Paginated,
  Permission,
} from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Lock, MessageSquare, Paperclip, Send, UserCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { Alert, Badge, EmptyState, Field, Modal, PageLoader, Pagination, StatusBadge } from '@/admin/components/ui';
import { api, assetUrl, errorMessage } from '@/admin/lib/api';
import { getAdminSocket } from '@/admin/lib/socket';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';
import { AdminUser } from './types';

interface ConversationRow {
  id: number; visitorName: string; visitorPhone: string; subject: string | null; status: ConversationStatus;
  lastMessageAt: string; unreadForAdmin: number; lastMessage: string | null;
  product: { id: number; name: string; reference: string } | null;
  order: { id: number; number: string } | null;
  assignedTo: { id: number; fullName: string } | null;
}
interface MessageRow { id: number; senderType: string; senderName: string | null; body: string; attachmentUrl: string | null; createdAt: string; isInternal: boolean }
interface ConversationDetail {
  id: number; visitorName: string; visitorPhone: string; visitorEmail: string | null; subject: string | null;
  status: ConversationStatus; internalNote: string | null; unreadForAdmin: number;
  assignedTo: { id: number; fullName: string } | null;
  customer: { id: number; fullName: string; phone: string; email: string | null; isGuest: boolean } | null;
  product: { id: number; name: string; reference: string; slug: string; price: number; availability: string; thumbUrl: string | null; stock: number } | null;
  order: { id: number; number: string; status: string; paymentStatus: string; total: number } | null;
  customerOrders: { id: number; number: string; status: string; total: number; createdAt: string }[];
  totalSpent: number;
  messages: MessageRow[];
}
interface QuickReply { id: number; title: string; body: string }

function List() {
  const [params, setParams] = useSearchParams();
  const qc = useQueryClient();
  const q = { q: params.get('q') ?? '', status: params.get('status') ?? '', mine: params.get('mine') ?? '', linked: params.get('linked') ?? '', page: Number(params.get('page') ?? 1), limit: 20 };
  const { data, isLoading } = useQuery({ queryKey: ['conversations', q], queryFn: () => api<Paginated<ConversationRow> & { totalUnread: number }>('/admin/conversations', { query: q }), placeholderData: (p) => p });

  useEffect(() => {
    const socket = getAdminSocket();
    const onNew = () => qc.invalidateQueries({ queryKey: ['conversations'] });
    socket.on('message.created', onNew);
    return () => { socket.off('message.created', onNew); };
  }, [qc]);

  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v); else next.delete(k);
    next.delete('page');
    setParams(next, { replace: true });
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Messagerie {!!data?.totalUnread && <Badge tone="danger">{data.totalUnread} non lu(s)</Badge>}</h1>
      </div>
      <div className="card flex flex-wrap items-center gap-3 p-4">
        <input className="input max-w-xs" placeholder="Nom, téléphone, commande, produit…" value={q.q} onChange={(e) => set('q', e.target.value)} />
        <select className="input max-w-[180px]" value={q.status} onChange={(e) => set('status', e.target.value)}>
          <option value="">Statut</option>
          {Object.entries(CONVERSATION_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select className="input max-w-[180px]" value={q.linked} onChange={(e) => set('linked', e.target.value)}>
          <option value="">Lié à</option>
          <option value="product">Un produit</option>
          <option value="order">Une commande</option>
        </select>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!q.mine} onChange={(e) => set('mine', e.target.checked ? 'true' : '')} /> Assignées à moi</label>
      </div>
      {isLoading ? <PageLoader /> : !data?.data.length ? (
        <EmptyState icon={<MessageSquare className="h-10 w-10" />} title="Aucune conversation" text="Les questions et messages clients apparaîtront ici." />
      ) : (
        <>
          <ul className="card divide-y divide-sand/60">
            {data.data.map((c) => (
              <li key={c.id}>
                <Link to={`/admin/messagerie/${c.id}`} className="flex items-start gap-3 px-4 py-3 hover:bg-ivory/60">
                  <span className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${c.unreadForAdmin ? 'bg-rose' : 'bg-sand'}`} />
                  <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2 text-sm">
                      <span className="font-semibold">{c.visitorName}</span>
                      <StatusBadge value={c.status} />
                      {c.product && <Badge tone="gold">{c.product.reference}</Badge>}
                      {c.order && <Badge tone="muted">{c.order.number}</Badge>}
                    </p>
                    <p className="truncate text-xs text-ink-soft">{c.subject ? `${c.subject} — ` : ''}{c.lastMessage ?? ''}</p>
                  </div>
                  <div className="text-right">
                    <p className="text-[10px] text-ink-soft">{formatDate(c.lastMessageAt, true)}</p>
                    {c.assignedTo && <p className="text-[10px] text-ink-soft">{c.assignedTo.fullName}</p>}
                    {!!c.unreadForAdmin && <Badge tone="danger">{c.unreadForAdmin}</Badge>}
                  </div>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination page={q.page} limit={q.limit} total={data.meta.total} onPage={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); }} />
        </>
      )}
    </div>
  );
}

function Detail() {
  const { id = '' } = useParams();
  const user = useAuthStore((s) => s.user);
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [body, setBody] = useState('');
  const [internal, setInternal] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [noteOpen, setNoteOpen] = useState(false);
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  const bottomRef = useRef<HTMLDivElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const canWrite = hasPermission(user, Permission.MESSAGES_WRITE);

  const { data: c, isLoading } = useQuery({ queryKey: ['conversation', id], queryFn: () => api<ConversationDetail>(`/admin/conversations/${id}`) });
  const { data: agents } = useQuery({ queryKey: ['users-list'], queryFn: () => api<AdminUser[]>('/admin/users'), enabled: assignOpen });
  const { data: quickReplies } = useQuery({ queryKey: ['quick-replies'], queryFn: () => api<QuickReply[]>('/admin/quick-replies') });

  useEffect(() => {
    const socket = getAdminSocket();
    const onNew = (payload: { conversationId: number }) => {
      if (payload.conversationId === Number(id)) qc.invalidateQueries({ queryKey: ['conversation', id] });
    };
    socket.on('message.created', onNew);
    socket.emit('conversation.watch', { conversationId: Number(id) });
    return () => { socket.off('message.created', onNew); socket.emit('conversation.unwatch', { conversationId: Number(id) }); };
  }, [id, qc]);

  useEffect(() => { bottomRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [c?.messages.length]);

  const reply = useMutation({
    mutationFn: () => api(`/admin/conversations/${id}/messages`, { method: 'POST', body: { body, isInternal: internal } }),
    onSuccess: () => { setBody(''); setInternal(false); qc.invalidateQueries({ queryKey: ['conversation', id, 'conversations'] }); },
    onError: (e) => setError(errorMessage(e)),
  });
  const setStatus = useMutation({
    mutationFn: (status: ConversationStatus) => api(`/admin/conversations/${id}/status`, { method: 'POST', body: { status } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['conversation', id, 'conversations'] }),
    onError: (e) => notify(errorMessage(e), 'error'),
  });
  const assign = useMutation({
    mutationFn: (adminUserId: number | null) => api(`/admin/conversations/${id}/assign`, { method: 'POST', body: { adminUserId } }),
    onSuccess: () => { setAssignOpen(false); qc.invalidateQueries({ queryKey: ['conversation', id] }); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });
  const saveNote = useMutation({
    mutationFn: () => api(`/admin/conversations/${id}/note`, { method: 'PUT', body: { note } }),
    onSuccess: () => { setNoteOpen(false); qc.invalidateQueries({ queryKey: ['conversation', id] }); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });
  const uploadAttachment = async (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    try {
      const up = await api<{ url: string }>('/admin/uploads', { method: 'POST', body: fd });
      await api(`/admin/conversations/${id}/messages`, { method: 'POST', body: { body: file.name, attachmentUrl: up.url, isInternal: internal } });
      qc.invalidateQueries({ queryKey: ['conversation', id] });
    } catch (e) {
      notify(errorMessage(e), 'error');
    }
  };

  if (isLoading || !c) return <PageLoader />;

  return (
    <div className="flex h-[calc(100vh-140px)] flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/messagerie" className="btn-ghost !px-2"><ArrowLeft className="h-4 w-4" /></Link>
        <div className="min-w-0 flex-1">
          <p className="font-semibold">{c.visitorName} · {formatPhone(c.visitorPhone)}</p>
          <p className="truncate text-xs text-ink-soft">{c.subject ?? 'Conversation'}{c.visitorEmail ? ` · ${c.visitorEmail}` : ''}</p>
        </div>
        <StatusBadge value={c.status} />
        {canWrite && (
          <>
            <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => setAssignOpen(true)}><UserCheck className="h-4 w-4" /> {c.assignedTo ? c.assignedTo.fullName : 'Assigner'}</button>
            {c.status !== ConversationStatus.RESOLVED && <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => setStatus.mutate(ConversationStatus.RESOLVED)}>Clôturer</button>}
            {c.status === ConversationStatus.RESOLVED && <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => setStatus.mutate(ConversationStatus.OPEN)}>Rouvrir</button>}
          </>
        )}
      </div>

      <div className="grid flex-1 gap-4 overflow-hidden lg:grid-cols-3">
        <div className="card flex min-h-0 flex-col lg:col-span-2">
          <div className="flex-1 space-y-2 overflow-y-auto p-4">
            {c.messages.map((m) => (
              <div key={m.id} className={`flex ${m.senderType === 'ADMIN' ? 'justify-end' : 'justify-start'}`}>
                <div className={`max-w-[80%] rounded-2xl px-3.5 py-2 text-sm ${m.isInternal ? 'border border-warning/40 bg-warning/10' : m.senderType === 'ADMIN' ? 'bg-gold/20' : 'bg-ivory'}`}>
                  <p className="text-[10px] uppercase tracking-wide text-ink-soft">
                    {m.isInternal && <Lock className="mr-1 inline h-3 w-3" />}{m.senderType === 'ADMIN' ? (m.senderName ?? 'Admin') : m.senderType === 'SYSTEM' ? 'DutCh’M' : c.visitorName} · {formatDate(m.createdAt, true)}
                  </p>
                  <p className="whitespace-pre-wrap">{m.body}</p>
                  {m.attachmentUrl && <a href={assetUrl(m.attachmentUrl)} target="_blank" rel="noreferrer" className="mt-1 inline-block text-xs text-gold-dark underline">Pièce jointe</a>}
                </div>
              </div>
            ))}
            <div ref={bottomRef} />
          </div>
          {canWrite && (
            <div className="border-t border-sand p-3">
              {!!quickReplies?.length && (
                <div className="mb-2 flex gap-1 overflow-x-auto">
                  {quickReplies.map((r) => <button key={r.id} className="badge shrink-0 bg-sand text-ink hover:bg-gold/20" onClick={() => setBody(r.body)}>{r.title}</button>)}
                </div>
              )}
              {error && <Alert tone="error">{error}</Alert>}
              <div className="flex items-end gap-2">
                <textarea className="input min-h-[44px] flex-1 resize-none" rows={1} placeholder={internal ? 'Note interne…' : 'Réponse au client…'} value={body} onChange={(e) => setBody(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (body.trim()) reply.mutate(); } }} />
                <button className="btn-ghost !min-h-[40px] !px-2" onClick={() => fileRef.current?.click()} aria-label="Joindre"><Paperclip className="h-4 w-4" /></button>
                <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={(e) => { if (e.target.files?.[0]) void uploadAttachment(e.target.files[0]); e.target.value = ''; }} />
                <label className={`flex items-center gap-1 text-xs ${internal ? 'font-semibold text-warning' : 'text-ink-soft'}`}><input type="checkbox" className="h-4 w-4 accent-gold" checked={internal} onChange={(e) => setInternal(e.target.checked)} /> Interne</label>
                <button className="btn-primary !min-h-[40px]" disabled={reply.isPending || !body.trim()} onClick={() => reply.mutate()}><Send className="h-4 w-4" /></button>
              </div>
            </div>
          )}
        </div>

        <div className="min-h-0 space-y-4 overflow-y-auto">
          {c.product && (
            <div className="card p-3 text-sm">
              <p className="mb-2 text-xs font-semibold uppercase text-ink-soft">Produit concerné</p>
              <div className="flex gap-3">
                {c.product.thumbUrl && <img src={assetUrl(c.product.thumbUrl)} alt="" className="h-14 w-14 rounded object-cover" />}
                <div>
                  <p className="font-medium">{c.product.name}</p>
                  <p className="text-xs text-ink-soft">{c.product.reference} · {formatFCFA(c.product.price)} · stock {c.product.stock}</p>
                </div>
              </div>
            </div>
          )}
          {c.order && (
            <div className="card p-3 text-sm">
              <p className="mb-1 text-xs font-semibold uppercase text-ink-soft">Commande liée</p>
              <Link to={`/admin/commandes/${c.order.id}`} className="font-mono text-xs font-semibold text-gold-dark hover:underline">{c.order.number}</Link>
              <p className="mt-1 text-xs"><StatusBadge value={c.order.status} /> <StatusBadge value={c.order.paymentStatus} /> · {formatFCFA(c.order.total)}</p>
            </div>
          )}
          <div className="card p-3 text-sm">
            <p className="mb-2 text-xs font-semibold uppercase text-ink-soft">Client {c.customer && <Link to={`/admin/clients/${c.customer.id}`} className="text-gold-dark hover:underline">(fiche)</Link>}</p>
            <p>{c.customer?.fullName ?? c.visitorName} {c.customer?.isGuest ? '(invité)' : ''}</p>
            <p className="text-xs text-ink-soft">{formatPhone(c.customer?.phone ?? c.visitorPhone)} {c.customer?.email ?? c.visitorEmail ?? ''}</p>
            <p className="mt-1 text-xs">Total dépensé : <strong>{formatFCFA(c.totalSpent)}</strong></p>
            {!!c.customerOrders.length && (
              <ul className="mt-2 space-y-1">
                {c.customerOrders.map((o) => (
                  <li key={o.id} className="text-xs"><Link to={`/admin/commandes/${o.id}`} className="font-mono text-gold-dark hover:underline">{o.number}</Link> · {formatFCFA(o.total)} · {formatDate(o.createdAt)}</li>
                ))}
              </ul>
            )}
          </div>
          <div className="card p-3 text-sm">
            <p className="mb-1 flex items-center justify-between text-xs font-semibold uppercase text-ink-soft">Note interne
              {canWrite && <button className="text-xs font-normal text-gold-dark" onClick={() => { setNote(c.internalNote ?? ''); setNoteOpen(true); }}>Modifier</button>}
            </p>
            <p className="whitespace-pre-wrap text-xs text-ink-soft">{c.internalNote ?? '—'}</p>
          </div>
        </div>
      </div>

      <Modal open={assignOpen} onClose={() => setAssignOpen(false)} title="Assigner la conversation">
        <ul className="space-y-1">
          <li><button className="w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-ivory" onClick={() => assign.mutate(null)}>— Désassigner</button></li>
          {agents?.map((a) => (
            <li key={a.id}><button className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-ivory" onClick={() => assign.mutate(a.id)}>{a.fullName} <span className="text-xs text-ink-soft">{a.roles.map((r) => r.name).join(', ')}</span></button></li>
          ))}
        </ul>
      </Modal>
      <Modal open={noteOpen} onClose={() => setNoteOpen(false)} title="Note interne">
        <Field label="Note (invisible pour le client)"><textarea className="input" rows={4} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <button className="btn-primary mt-3 w-full" disabled={saveNote.isPending} onClick={() => saveNote.mutate()}>Enregistrer</button>
      </Modal>
    </div>
  );
}

export default function Messaging() {
  const { id } = useParams();
  return id ? <Detail /> : <List />;
}
