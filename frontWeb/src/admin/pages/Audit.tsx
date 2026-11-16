import { formatDate, Paginated } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { ScrollText } from 'lucide-react';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { DataTable, EmptyState, Field, Modal, PageLoader, Pagination } from '@/admin/components/ui';
import { api } from '@/admin/lib/api';
import { AdminUser } from './types';

interface AuditRow {
  id: number; adminUserId: number | null; adminName: string | null; action: string; entityType: string; entityId: string | null;
  before: unknown; after: unknown; ip: string | null; userAgent: string | null; createdAt: string;
}

const ENTITY_LABELS: Record<string, string> = {
  order: 'Commande', product: 'Produit', product_variant: 'Variante', category: 'Catégorie', collection: 'Collection',
  supplier: 'Fournisseur', attribute: 'Attribut', arrival: 'Arrivage', arrival_item: "Article d'arrivage", shipment: 'Expédition',
  shipment_step: 'Étape', payment: 'Paiement', refund: 'Remboursement', customer: 'Client', coupon: 'Coupon',
  page: 'Page', faq: 'FAQ', banner: 'Bannière', review: 'Avis', notification_template: 'Gabarit', admin_user: 'Utilisateur',
  role: 'Rôle', delivery_zone: 'Zone', settings: 'Paramètres', conversation: 'Conversation', quick_reply: 'Réponse rapide',
  stock_movement: 'Mouvement de stock',
};

export default function Audit() {
  const [params, setParams] = useSearchParams();
  const [detail, setDetail] = useState<AuditRow | null>(null);
  const { data: users } = useQuery({ queryKey: ['users'], queryFn: () => api<AdminUser[]>('/admin/users') });
  const q = {
    adminUserId: params.get('admin') ?? '', entityType: params.get('entityType') ?? '', action: params.get('action') ?? '',
    entityId: params.get('entityId') ?? '', from: params.get('from') ?? '', to: params.get('to') ?? '', page: Number(params.get('page') ?? 1), limit: 50,
  };
  const { data, isLoading } = useQuery({ queryKey: ['audit', q], queryFn: () => api<Paginated<AuditRow>>('/admin/audit', { query: { ...q, adminUserId: q.adminUserId || undefined, entityType: q.entityType || undefined, action: q.action || undefined, entityId: q.entityId || undefined, from: q.from || undefined, to: q.to || undefined } }), placeholderData: (p) => p });
  const set = (k: string, v: string) => {
    const n = new URLSearchParams(params);
    if (v) n.set(k, v); else n.delete(k);
    if (k !== 'page') n.delete('page');
    setParams(n, { replace: true });
  };

  return (
    <div className="space-y-4">
      <h1 className="text-2xl">Journal d'audit</h1>
      <div className="card flex flex-wrap items-end gap-3 p-4">
        <Field label="Utilisateur"><select className="input" value={q.adminUserId} onChange={(e) => set('admin', e.target.value)}>
          <option value="">Tous</option>
          {users?.map((u) => <option key={u.id} value={u.id}>{u.fullName}</option>)}
        </select></Field>
        <Field label="Entité"><select className="input" value={q.entityType} onChange={(e) => set('entityType', e.target.value)}>
          <option value="">Toutes</option>
          {Object.entries(ENTITY_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select></Field>
        <Field label="Action"><input className="input w-44" placeholder="ex. order.update" value={q.action} onChange={(e) => set('action', e.target.value)} /></Field>
        <Field label="ID entité"><input className="input w-28" value={q.entityId} onChange={(e) => set('entityId', e.target.value.replace(/\D/g, ''))} /></Field>
        <Field label="Du"><input className="input" type="date" value={q.from} onChange={(e) => set('from', e.target.value)} /></Field>
        <Field label="Au"><input className="input" type="date" value={q.to} onChange={(e) => set('to', e.target.value)} /></Field>
      </div>

      {isLoading ? <PageLoader /> : !data?.data.length ? (
        <EmptyState icon={<ScrollText className="h-10 w-10" />} title="Aucune entrée" text="Aucune action ne correspond à ces filtres." />
      ) : (
        <>
          <DataTable head={['Date', 'Utilisateur', 'Action', 'Entité', 'IP', '']}>
            {data.data.map((a) => (
              <tr key={a.id}>
                <td className="td whitespace-nowrap text-xs">{formatDate(a.createdAt, true)}</td>
                <td className="td text-sm">{a.adminName ?? `Admin #${a.adminUserId ?? '?'}`}</td>
                <td className="td font-mono text-xs">{a.action}</td>
                <td className="td text-xs">{ENTITY_LABELS[a.entityType] ?? a.entityType}{a.entityId ? ` #${a.entityId}` : ''}</td>
                <td className="td font-mono text-xs">{a.ip ?? '—'}</td>
                <td className="td"><button className="btn-ghost !min-h-[30px] !px-2 text-xs" onClick={() => setDetail(a)}>Détails</button></td>
              </tr>
            ))}
          </DataTable>
          <Pagination page={q.page} limit={q.limit} total={data.meta.total} onPage={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); }} />
        </>
      )}

      <Modal open={!!detail} onClose={() => setDetail(null)} title={detail ? `${detail.action} — ${ENTITY_LABELS[detail.entityType] ?? detail.entityType} #${detail.entityId ?? ''}` : ''} wide>
        {detail && (
          <div className="grid gap-3 text-xs sm:grid-cols-2">
            <div>
              <p className="mb-1 font-semibold text-ink-soft">Avant</p>
              <pre className="max-h-72 overflow-auto rounded-xl bg-ink p-3 font-mono text-[11px] text-ivory">{detail.before ? JSON.stringify(detail.before, null, 2) : '—'}</pre>
            </div>
            <div>
              <p className="mb-1 font-semibold text-ink-soft">Après</p>
              <pre className="max-h-72 overflow-auto rounded-xl bg-ink p-3 font-mono text-[11px] text-ivory">{detail.after ? JSON.stringify(detail.after, null, 2) : '—'}</pre>
            </div>
            <p className="text-ink-soft sm:col-span-2">{detail.userAgent ?? ''}</p>
          </div>
        )}
      </Modal>
    </div>
  );
}
