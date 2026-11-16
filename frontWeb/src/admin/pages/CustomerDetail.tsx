import { formatDate, formatFCFA, formatPhone, Permission } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Heart, MessageSquare, ShieldOff } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Alert, Badge, Field, Modal, PageLoader, StatusBadge } from '@/admin/components/ui';
import { api, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';

interface Address { id: number; label: string | null; city: string; zoneName: string | null; district: string | null; landmark: string | null; isDefault: boolean }
interface CustomerDetail {
  id: number; fullName: string; phone: string; email: string | null; isGuest: boolean; newsletterConsent: boolean;
  phoneVerifiedAt: string | null; registeredAt: string | null; createdAt: string; notes: string | null; deletedAt: string | null;
  addresses: Address[]; totalSpent: number; wishlistCount: number;
  orders: { id: number; number: string; createdAt: string; status: string; paymentStatus: string; total: number }[];
  conversations: { id: number; status: string; subject: string | null; lastMessageAt: string }[];
}

export default function CustomerDetail() {
  const { id = '' } = useParams();
  const user = useAuthStore((s) => s.user);
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [edit, setEdit] = useState(false);
  const [form, setForm] = useState({ fullName: '', email: '', notes: '', newsletterConsent: false });
  const [error, setError] = useState<string | null>(null);
  const { data: c, isLoading } = useQuery({ queryKey: ['customer', id], queryFn: () => api<CustomerDetail>(`/admin/customers/${id}`) });
  const canWrite = hasPermission(user, Permission.CUSTOMERS_WRITE);

  const save = useMutation({
    mutationFn: () => api(`/admin/customers/${id}`, { method: 'PATCH', body: { fullName: form.fullName || undefined, email: form.email || null, notes: form.notes || null, newsletterConsent: form.newsletterConsent } }),
    onSuccess: () => { notify('Client mis à jour'); setEdit(false); qc.invalidateQueries({ queryKey: ['customer', id] }); },
    onError: (e) => setError(errorMessage(e)),
  });
  const anonymize = useMutation({
    mutationFn: () => api(`/admin/customers/${id}/anonymize`, { method: 'POST' }),
    onSuccess: () => { notify('Données personnelles anonymisées'); qc.invalidateQueries({ queryKey: ['customer', id] }); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });

  if (isLoading || !c) return <PageLoader />;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/clients" className="btn-ghost !px-2"><ArrowLeft className="h-4 w-4" /></Link>
        <h1 className="text-2xl">{c.fullName}</h1>
        {c.deletedAt && <Badge tone="danger">Compte anonymisé</Badge>}
        {c.isGuest && !c.deletedAt && <Badge>Invité</Badge>}
        {c.phoneVerifiedAt && <Badge tone="success">Téléphone vérifié</Badge>}
        <div className="ml-auto flex gap-2">
          {canWrite && !c.deletedAt && (
            <>
              <button className="btn-secondary" onClick={() => { setForm({ fullName: c.fullName, email: c.email ?? '', notes: c.notes ?? '', newsletterConsent: c.newsletterConsent }); setEdit(true); setError(null); }}>Modifier</button>
              <button className="btn-danger" onClick={() => confirm(`Anonymiser ${c.fullName} ? Ses données personnelles seront effacées (les commandes restent pour la comptabilité).`) && anonymize.mutate()}><ShieldOff className="h-4 w-4" /> Anonymiser</button>
            </>
          )}
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4">
          <div className="card p-4 text-sm">
            <h2 className="mb-2 font-semibold">Coordonnées</h2>
            <p>{formatPhone(c.phone)}</p>
            <p className="text-ink-soft">{c.email ?? 'Pas d\'e-mail'}</p>
            <p className="mt-2 text-xs text-ink-soft">Client depuis le {formatDate(c.createdAt)}{c.registeredAt ? ` · compte créé le ${formatDate(c.registeredAt)}` : ''}</p>
            <p className="mt-2"><Badge tone={c.newsletterConsent ? 'rose' : 'muted'}>{c.newsletterConsent ? 'Abonné newsletter' : 'Pas de newsletter'}</Badge></p>
          </div>
          <div className="card p-4 text-sm">
            <h2 className="mb-2 font-semibold">Adresses</h2>
            {c.addresses.length ? (
              <ul className="space-y-2">
                {c.addresses.map((a) => (
                  <li key={a.id} className="rounded-lg bg-ivory p-3 text-xs">
                    {a.label && <p className="font-semibold">{a.label}{a.isDefault && <Badge tone="gold"> par défaut</Badge>}</p>}
                    <p>{[a.district, a.zoneName, a.city].filter(Boolean).join(', ')}</p>
                    {a.landmark && <p className="text-ink-soft">Repère : {a.landmark}</p>}
                  </li>
                ))}
              </ul>
            ) : <p className="text-xs text-ink-soft">Aucune adresse enregistrée.</p>}
          </div>
          <div className="card p-4 text-sm">
            <h2 className="mb-2 font-semibold">Notes internes</h2>
            <p className="whitespace-pre-wrap text-xs text-ink-soft">{c.notes ?? '—'}</p>
          </div>
        </div>
        <div className="space-y-4 lg:col-span-2">
          <div className="card p-4">
            <h2 className="mb-3 flex items-center justify-between font-semibold">Commandes ({c.orders.length})<Badge tone="gold">{formatFCFA(c.totalSpent)} encaissés</Badge></h2>
            <ul className="divide-y divide-sand/60">
              {c.orders.map((o) => (
                <li key={o.id}>
                  <Link to={`/admin/commandes/${o.id}`} className="flex items-center justify-between gap-3 py-2 text-sm hover:bg-ivory/50">
                    <span className="font-mono text-xs font-semibold">{o.number}</span>
                    <StatusBadge value={o.status} />
                    <StatusBadge value={o.paymentStatus} />
                    <span className="font-semibold">{formatFCFA(o.total)}</span>
                    <span className="text-xs text-ink-soft">{formatDate(o.createdAt)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </div>
          <div className="card p-4">
            <h2 className="mb-3 font-semibold"><MessageSquare className="mr-1 inline h-4 w-4" /> Conversations ({c.conversations.length}) · <Heart className="inline h-4 w-4" /> {c.wishlistCount} favori(s)</h2>
            <ul className="divide-y divide-sand/60">
              {c.conversations.map((cv) => (
                <li key={cv.id}><Link to={`/admin/messagerie/${cv.id}`} className="flex items-center justify-between py-2 text-sm hover:bg-ivory/50">
                  <span>{cv.subject ?? `Conversation #${cv.id}`}</span>
                  <StatusBadge value={cv.status} />
                  <span className="text-xs text-ink-soft">{formatDate(cv.lastMessageAt, true)}</span>
                </Link></li>
              ))}
            </ul>
          </div>
        </div>
      </div>

      <Modal open={edit} onClose={() => setEdit(false)} title="Modifier le client">
        <div className="space-y-3">
          <Field label="Nom complet"><input className="input" value={form.fullName} onChange={(e) => setForm({ ...form, fullName: e.target.value })} /></Field>
          <Field label="E-mail"><input className="input" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
          <Field label="Notes internes"><textarea className="input" rows={4} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={form.newsletterConsent} onChange={(e) => setForm({ ...form, newsletterConsent: e.target.checked })} /> Consentement newsletter</label>
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary w-full" disabled={save.isPending} onClick={() => save.mutate()}>Enregistrer</button>
        </div>
      </Modal>
    </div>
  );
}
