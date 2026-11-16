import {
  AvailabilityMode,
  AVAILABILITY_LABELS,
  CategoryDto,
  formatDate,
  formatFCFA,
  Paginated,
  Permission,
  StockMovementType,
} from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BellRing, Boxes, PackageCheck, Wrench } from 'lucide-react';
import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Alert, Badge, DataTable, EmptyState, Field, Modal, PageLoader, Pagination } from '@/admin/components/ui';
import { api, download, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';

interface StockRow {
  variantId: number; productId: number; productName: string; reference: string; category: string | null;
  sku: string; variantName: string; stockOnHand: number; stockReserved: number; stockAvailable: number;
  lowStockThreshold: number; availabilityMode: AvailabilityMode; costPrice: number | null; isLow: boolean;
}
interface MovementRow {
  id: number; createdAt: string; type: StockMovementType; quantity: number; reason: string;
  onHandAfter: number; reservedAfter: number; orderId: number | null; arrivalId: number | null;
  sku: string; productName: string; adminUser: string | null;
}
interface AlertRow { variantId: number; productName: string; variantName: string; sku: string; stockAvailable: number; pending: number; total: number }

const MOVE_LABELS: Record<StockMovementType, string> = { IN: 'Entrée', OUT: 'Sortie', RESERVE: 'Réservation', RELEASE: 'Libération', ADJUST: 'Ajustement' };
const TABS = ['Stock', 'Mouvements', 'Alertes retour en stock'] as const;

export default function Stocks() {
  const user = useAuthStore((s) => s.user);
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [params, setParams] = useSearchParams();
  const tab = (params.get('tab') as (typeof TABS)[number]) ?? 'Stock';
  const [adjust, setAdjust] = useState<StockRow | null>(null);
  const [adjForm, setAdjForm] = useState({ mode: 'delta', value: '', reason: '', type: 'ADJUST' });
  const [error, setError] = useState<string | null>(null);
  const canWrite = hasPermission(user, Permission.STOCK_WRITE);

  const q = {
    q: params.get('q') ?? '', categoryId: params.get('categoryId') ?? '', lowStock: params.get('lowStock') ?? '',
    type: params.get('type') ?? '', from: params.get('from') ?? '', to: params.get('to') ?? '',
    page: Number(params.get('page') ?? 1), limit: 30,
  };
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v); else next.delete(k);
    next.delete('page');
    setParams(next, { replace: true });
  };

  const { data: categories } = useQuery({ queryKey: ['categories-admin'], queryFn: () => api<CategoryDto[]>('/admin/categories'), staleTime: 5 * 60_000 });
  const overview = useQuery({ queryKey: ['stock', q], queryFn: () => api<Paginated<StockRow>>('/admin/stock', { query: q }), enabled: tab === 'Stock', placeholderData: (p) => p });
  const movements = useQuery({ queryKey: ['movements', q], queryFn: () => api<Paginated<MovementRow>>('/admin/stock/movements', { query: q }), enabled: tab === 'Mouvements', placeholderData: (p) => p });
  const alerts = useQuery({ queryKey: ['stock-alerts'], queryFn: () => api<AlertRow[]>('/admin/stock/alerts'), enabled: tab === 'Alertes retour en stock' });

  const adjustMut = useMutation({
    mutationFn: () => api(`/admin/stock/${adjust!.variantId}/adjust`, { method: 'POST', body: { mode: adjForm.mode, value: Number(adjForm.value), reason: adjForm.reason, type: adjForm.type === 'IN' ? 'IN' : undefined } }),
    onSuccess: () => { notify('Stock ajusté'); setAdjust(null); qc.invalidateQueries({ queryKey: ['stock'] }); },
    onError: (e) => setError(errorMessage(e)),
  });
  const sendAlert = useMutation({
    mutationFn: (variantId: number) => api<{ queued: number }>(`/admin/stock/alerts/${variantId}/send`, { method: 'POST' }),
    onSuccess: (r) => notify(`${r.queued} client(s) notifié(s)`),
    onError: (e) => notify(errorMessage(e), 'error'),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Stocks</h1>
        <button className="btn-secondary" onClick={() => download('/admin/exports/stock', { format: 'xlsx' }).catch((e) => notify(errorMessage(e), 'error'))}>Exporter (Excel)</button>
      </div>
      <div className="flex gap-1 overflow-x-auto rounded-lg bg-sand/50 p-1" role="tablist">
        {TABS.map((t) => <button key={t} role="tab" aria-selected={tab === t} onClick={() => set('tab', t)} className={`whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold ${tab === t ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}>{t}</button>)}
      </div>
      <div className="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
        <input className="input" placeholder="Produit, référence, SKU…" value={q.q} onChange={(e) => set('q', e.target.value)} />
        <select className="input" value={q.categoryId} onChange={(e) => set('categoryId', e.target.value)}>
          <option value="">Catégorie</option>
          {categories?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        {tab === 'Stock' && <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!q.lowStock} onChange={(e) => set('lowStock', e.target.checked ? 'true' : '')} /> Stock faible uniquement</label>}
        {tab === 'Mouvements' && (
          <>
            <select className="input" value={q.type} onChange={(e) => set('type', e.target.value)}>
              <option value="">Type</option>
              {Object.entries(MOVE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
            <input className="input" type="date" value={q.from} onChange={(e) => set('from', e.target.value)} aria-label="Du" />
            <input className="input" type="date" value={q.to} onChange={(e) => set('to', e.target.value)} aria-label="Au" />
          </>
        )}
      </div>

      {tab === 'Stock' && (
        overview.isLoading ? <PageLoader /> : !overview.data?.data.length ? (
          <EmptyState icon={<Boxes className="h-10 w-10" />} title="Aucune variante" text="Aucune variante ne correspond à ces filtres." />
        ) : (
          <>
            <DataTable head={['Produit', 'SKU', 'Physique', 'Réservé', 'Disponible', 'Seuil', 'Valeur', 'Dispo', '']}>
              {overview.data.data.map((r) => (
                <tr key={r.variantId} className={r.isLow ? 'bg-danger/5' : ''}>
                  <td className="td"><Link to={`/admin/catalogue/${r.productId}`} className="font-medium text-gold-dark hover:underline">{r.productName}</Link><p className="text-xs text-ink-soft">{r.variantName} · {r.category ?? '—'}</p></td>
                  <td className="td font-mono text-xs">{r.sku}</td>
                  <td className="td">{r.stockOnHand}</td>
                  <td className="td">{r.stockReserved}</td>
                  <td className={`td font-semibold ${r.isLow ? 'text-danger' : ''}`}>{r.stockAvailable}</td>
                  <td className="td">{r.lowStockThreshold}</td>
                  <td className="td text-xs">{r.costPrice ? formatFCFA(r.costPrice * r.stockOnHand) : '—'}</td>
                  <td className="td"><Badge tone={r.availabilityMode === AvailabilityMode.IN_STOCK ? 'success' : r.availabilityMode === AvailabilityMode.PREORDER ? 'warning' : 'danger'}>{AVAILABILITY_LABELS[r.availabilityMode]}</Badge></td>
                  <td className="td">{canWrite && <button className="btn-ghost !min-h-[30px] !px-2 text-xs" onClick={() => { setAdjust(r); setAdjForm({ mode: 'delta', value: '', reason: '', type: 'ADJUST' }); setError(null); }}><Wrench className="h-4 w-4" /> Ajuster</button>}</td>
                </tr>
              ))}
            </DataTable>
            <Pagination page={q.page} limit={q.limit} total={overview.data.meta.total} onPage={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); }} />
          </>
        )
      )}

      {tab === 'Mouvements' && (
        movements.isLoading ? <PageLoader /> : (
          <>
            <DataTable head={['Date', 'Type', 'Qté', 'Après (phys./réservé)', 'Motif', 'Produit', 'Commande', 'Par']}>
              {movements.data?.data.map((m) => (
                <tr key={m.id}>
                  <td className="td text-xs">{formatDate(m.createdAt, true)}</td>
                  <td className="td"><Badge tone={m.type === 'IN' ? 'success' : m.type === 'OUT' ? 'danger' : 'muted'}>{MOVE_LABELS[m.type]}</Badge></td>
                  <td className="td font-semibold">{m.quantity > 0 ? `+${m.quantity}` : m.quantity}</td>
                  <td className="td text-xs">{m.onHandAfter} / {m.reservedAfter}</td>
                  <td className="td text-xs">{m.reason}</td>
                  <td className="td text-xs">{m.productName} <span className="text-ink-soft">({m.sku})</span></td>
                  <td className="td text-xs">{m.orderId ? <Link className="text-gold-dark" to={`/admin/commandes/${m.orderId}`}>#{m.orderId}</Link> : m.arrivalId ? `Arrivage #${m.arrivalId}` : '—'}</td>
                  <td className="td text-xs">{m.adminUser ?? 'Système'}</td>
                </tr>
              ))}
            </DataTable>
            {movements.data && <Pagination page={q.page} limit={q.limit} total={movements.data.meta.total} onPage={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); }} />}
          </>
        )
      )}

      {tab === 'Alertes retour en stock' && (
        alerts.isLoading ? <PageLoader /> : !alerts.data?.length ? (
          <EmptyState icon={<BellRing className="h-10 w-10" />} title="Aucune alerte" text="Les clients qui demandent une alerte apparaîtront ici." />
        ) : (
          <DataTable head={['Produit', 'SKU', 'Dispo', 'En attente', 'Total', '']}>
            {alerts.data.map((a) => (
              <tr key={a.variantId}>
                <td className="td font-medium">{a.productName} <span className="text-xs text-ink-soft">· {a.variantName}</span></td>
                <td className="td font-mono text-xs">{a.sku}</td>
                <td className="td">{a.stockAvailable}</td>
                <td className="td"><Badge tone={a.pending ? 'warning' : 'success'}>{a.pending}</Badge></td>
                <td className="td">{a.total}</td>
                <td className="td">{a.pending > 0 && canWrite && <button className="btn-secondary !min-h-[32px] text-xs" onClick={() => sendAlert.mutate(a.variantId)}><PackageCheck className="h-4 w-4" /> Notifier les clients</button>}</td>
              </tr>
            ))}
          </DataTable>
        )
      )}

      <Modal open={!!adjust} onClose={() => setAdjust(null)} title={`Ajuster — ${adjust?.productName ?? ''} (${adjust?.sku ?? ''})`}>
        {adjust && (
          <div className="space-y-3">
            <p className="text-sm text-ink-soft">Physique actuel : <strong>{adjust.stockOnHand}</strong> · Réservé : <strong>{adjust.stockReserved}</strong></p>
            <Field label="Mode">
              <select className="input" value={adjForm.mode} onChange={(e) => setAdjForm({ ...adjForm, mode: e.target.value })}>
                <option value="delta">Ajouter / retirer des unités</option>
                <option value="set">Fixer le stock physique</option>
              </select>
            </Field>
            <Field label={adjForm.mode === 'set' ? 'Nouveau stock physique' : 'Variation (+10 ou -2)'}>
              <input className="input" value={adjForm.value} onChange={(e) => setAdjForm({ ...adjForm, value: e.target.value.replace(/[^\d-]/g, '') })} inputMode="numeric" />
            </Field>
            <Field label="Type">
              <select className="input" value={adjForm.type} onChange={(e) => setAdjForm({ ...adjForm, type: e.target.value })}>
                <option value="ADJUST">Ajustement / inventaire</option>
                <option value="IN">Entrée (réception, retour)</option>
              </select>
            </Field>
            <Field label="Motif (obligatoire)"><input className="input" value={adjForm.reason} onChange={(e) => setAdjForm({ ...adjForm, reason: e.target.value })} placeholder="Ex. inventaire physique, casse…" /></Field>
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary w-full" disabled={adjustMut.isPending || adjForm.value === '' || adjForm.reason.trim().length < 3} onClick={() => adjustMut.mutate()}>Valider l'ajustement</button>
          </div>
        )}
      </Modal>
    </div>
  );
}
