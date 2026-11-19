import {
  formatDate,
  formatFCFA,
  formatPhone,
  ORDER_STATUS_LABELS,
  OrderStatus,
  Paginated,
  PAYMENT_METHOD_LABELS,
  Permission,
  STEP_LABELS,
  ShipmentStep,
} from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { Banknote, ChartBar, Download, FileSpreadsheet, PackageSearch, Warehouse } from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import { Badge, DataTable, EmptyState, PageLoader, Pagination, StatusBadge } from '@/admin/components/ui';
import { api, download, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';
import { PaymentAdminRow } from './types';

interface SalesReport {
  from: string; to: string; groupBy: string;
  summary: { orders: number; total: number; paid: number; averageBasket: number };
  rows: Record<string, number | string>[];
}
interface PreorderLine {
  orderId: number; number: string; customer: string; product: string; reference: string; quantity: number; receivedQty: number;
  arrival: string | null; arrivalStep: ShipmentStep | null; shipmentStep: ShipmentStep | null; estimatedDate: string | null;
  isLate: boolean; shipmentStepLabel: string | null; arrivalStepLabel: string | null;
}
interface StockValuation { rows: { reference: string; product: string; sku: string; variant: string; category: string; onHand: number; reserved: number; unitCost: number; unitPrice: number; costValue: number; retailValue: number }[]; totals: { units: number; costValue: number; retailValue: number } }
interface ReconItem { date: string; provider: string; expected: number; recorded: number; gap: number }

const TABS = [
  ['ventes', 'Ventes', ChartBar],
  ['paiements', 'Paiements', Banknote],
  ['precommandes', 'Précommandes', PackageSearch],
  ['stock', 'Valorisation stock', Warehouse],
  ['exports', 'Exports', FileSpreadsheet],
] as const;

const GROUPS = [['day', 'Par jour'], ['month', 'Par mois'], ['product', 'Par produit'], ['category', 'Par catégorie'], ['paymentMethod', 'Par moyen de paiement'], ['zone', 'Par zone']] as const;
const EXPORTS: { entity: string; label: string }[] = [
  { entity: 'orders', label: 'Commandes' }, { entity: 'sales', label: 'Ventes (lignes)' }, { entity: 'order-items', label: 'Lignes de commande' },
  { entity: 'stock', label: 'Stocks' }, { entity: 'customers', label: 'Clients' }, { entity: 'payments', label: 'Paiements' },
  { entity: 'newsletter', label: 'Newsletter' }, { entity: 'audit-logs', label: "Journal d'audit" },
];

export default function Reports() {
  const user = useAuthStore((s) => s.user);
  const notify = useUiStore((s) => s.notify);
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'ventes';
  const set = (k: string, v: string) => {
    const n = new URLSearchParams(params);
    if (v) n.set(k, v); else n.delete(k);
    if (k !== 'page') n.delete('page');
    setParams(n, { replace: true });
  };
  const q = {
    from: params.get('from') ?? '', to: params.get('to') ?? '', groupBy: params.get('groupBy') ?? 'day',
    status: params.get('status') ?? '', provider: params.get('provider') ?? '', search: params.get('q') ?? '',
    duplicates: params.get('duplicates') ?? '', page: Number(params.get('page') ?? 1),
  };

  const sales = useQuery({ queryKey: ['report-sales', q], queryFn: () => api<SalesReport>('/admin/reports/sales', { query: { from: q.from || undefined, to: q.to || undefined, groupBy: q.groupBy } }), enabled: tab === 'ventes' });
  const payments = useQuery({ queryKey: ['payments', q], queryFn: () => api<Paginated<PaymentAdminRow>>('/admin/payments', { query: { from: q.from || undefined, to: q.to || undefined, status: q.status || undefined, provider: q.provider || undefined, q: q.search || undefined, duplicates: q.duplicates || undefined, page: q.page, limit: 25 } }), enabled: tab === 'paiements', placeholderData: (p) => p });
  const recon = useQuery({ queryKey: ['recon', q.from, q.to], queryFn: () => api<{ items: ReconItem[] } | ReconItem[]>('/admin/payments/reconciliation', { query: { from: q.from || undefined, to: q.to || undefined } }), enabled: tab === 'paiements' });
  const preorders = useQuery({ queryKey: ['report-preorders'], queryFn: () => api<PreorderLine[]>('/admin/reports/preorders'), enabled: tab === 'precommandes' });
  const valuation = useQuery({ queryKey: ['report-stock'], queryFn: () => api<StockValuation>('/admin/reports/stock-valuation'), enabled: tab === 'stock' });

  const salesCols = sales.data?.rows.length ? Object.keys(sales.data.rows[0]) : [];
  const COL_LABELS: Record<string, string> = { label: 'Période', orders: 'Commandes', total: 'Total', discount: 'Remises', shipping: 'Livraisons', quantity: 'Qté', paid: 'Encaissé' };
  const isMoneyCol = (k: string) => ['total', 'discount', 'shipping', 'paid'].includes(k);

  return (
    <div className="space-y-4">
      <h1 className="text-2xl">Rapports</h1>
      <div className="flex gap-1 overflow-x-auto rounded-lg bg-sand/50 p-1" role="tablist">
        {TABS.map(([v, l, Icon]) => <button key={v} role="tab" aria-selected={tab === v} onClick={() => set('tab', v)} className={`flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold ${tab === v ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}><Icon className="h-4 w-4" />{l}</button>)}
      </div>
      <div className="card flex flex-wrap items-center gap-3 p-4">
        {(tab === 'ventes' || tab === 'paiements' || tab === 'exports') && (
          <>
            <input className="input w-auto" type="date" value={q.from} onChange={(e) => set('from', e.target.value)} aria-label="Du" />
            <input className="input w-auto" type="date" value={q.to} onChange={(e) => set('to', e.target.value)} aria-label="Au" />
          </>
        )}
        {tab === 'ventes' && <select className="input w-auto" value={q.groupBy} onChange={(e) => set('groupBy', e.target.value)}>{GROUPS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select>}
        {tab === 'paiements' && (
          <>
            <input className="input max-w-xs" placeholder="Référence, commande, payeur…" value={q.search} onChange={(e) => set('q', e.target.value)} />
            <select className="input w-auto" value={q.status} onChange={(e) => set('status', e.target.value)}>
              <option value="">Statut</option>
              {['PENDING', 'SUCCEEDED', 'FAILED', 'EXPIRED', 'REFUNDED', 'PARTIALLY_REFUNDED'].map((s) => <option key={s} value={s}>{s}</option>)}
            </select>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!q.duplicates} onChange={(e) => set('duplicates', e.target.checked ? 'true' : '')} /> Doublons uniquement</label>
          </>
        )}
      </div>

      {tab === 'ventes' && (
        sales.isLoading ? <PageLoader /> : !sales.data ? null : (
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-4">
              {[['Commandes', String(sales.data.summary.orders)], ['Total commandé', formatFCFA(sales.data.summary.total)], ['Encaissé', formatFCFA(sales.data.summary.paid)], ['Panier moyen', formatFCFA(sales.data.summary.averageBasket)]].map(([l, v]) => (
                <div key={l} className="card p-4"><p className="text-xs uppercase tracking-wide text-ink-soft">{l}</p><p className="text-lg font-bold">{v}</p></div>
              ))}
            </div>
            <DataTable head={salesCols.map((k) => COL_LABELS[k] ?? k)}>
              {sales.data.rows.map((r, i) => (
                <tr key={i}>
                  {salesCols.map((k) => <td key={k} className={`td ${k === 'label' ? 'font-medium' : ''}`}>{isMoneyCol(k) ? formatFCFA(Number(r[k])) : r[k]}</td>)}
                </tr>
              ))}
            </DataTable>
          </div>
        )
      )}

      {tab === 'paiements' && (
        <div className="space-y-4">
          {Array.isArray(recon.data) && recon.data.length > 0 && (
            <div className="card p-4">
              <h2 className="mb-2 font-semibold">Rapprochement</h2>
              <ul className="space-y-1 text-sm">
                {recon.data.map((r, i) => (
                  <li key={i} className={`flex justify-between gap-3 rounded-lg px-3 py-1.5 ${r.gap ? 'bg-warning/10' : 'bg-ivory/60'}`}>
                    <span>{formatDate(r.date)} · {r.provider}</span>
                    <span className={r.gap ? 'font-semibold text-warning' : ''}>Attendu {formatFCFA(r.expected)} · enregistré {formatFCFA(r.recorded)}{r.gap ? ` · écart ${formatFCFA(r.gap)}` : ''}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
          {payments.isLoading ? <PageLoader /> : (
            <>
              <DataTable head={['Créé', 'Commande', 'Opérateur', 'Type', 'Montant', 'Statut', 'Payeur', 'Référence', '']}>
                {payments.data?.data.map((p) => (
                  <tr key={p.id} className={p.isDuplicate ? 'bg-danger/5' : ''}>
                    <td className="td text-xs">{formatDate(p.createdAt, true)}</td>
                    <td className="td"><Link to={`/admin/commandes/${p.orderId}`} className="font-mono text-xs font-semibold text-gold-dark hover:underline">{p.orderNumber}</Link></td>
                    <td className="td text-xs">{p.provider}{p.isManual ? ' (manuel)' : ''}{p.isDuplicate && <Badge tone="danger"> doublon</Badge>}</td>
                    <td className="td text-xs">{p.kind} · {PAYMENT_METHOD_LABELS[p.method as keyof typeof PAYMENT_METHOD_LABELS] ?? p.method}</td>
                    <td className="td font-semibold">{formatFCFA(p.amount)}</td>
                    <td className="td"><Badge tone={p.status === 'SUCCEEDED' ? 'success' : p.status === 'PENDING' ? 'warning' : 'danger'}>{p.status}</Badge></td>
                    <td className="td text-xs">{p.payerPhone ? formatPhone(p.payerPhone) : '—'}</td>
                    <td className="td font-mono text-xs">{p.providerReference}</td>
                    <td className="td"><Link to={`/admin/commandes/${p.orderId}`} className="btn-ghost !min-h-[30px] !px-2 text-xs">Voir</Link></td>
                  </tr>
                ))}
              </DataTable>
              {payments.data && <Pagination page={q.page} limit={25} total={payments.data.meta.total} onPage={(p) => set('page', String(p))} />}
            </>
          )}
        </div>
      )}

      {tab === 'precommandes' && (
        preorders.isLoading ? <PageLoader /> : !preorders.data?.length ? (
          <EmptyState icon={<PackageSearch className="h-10 w-10" />} title="Aucune précommande" text="Aucune ligne de précommande en cours." />
        ) : (
          <DataTable head={['Commande', 'Client', 'Produit', 'Qté', 'Arrivage', 'Expédition', 'Estimation', '']}>
            {preorders.data.map((l, i) => (
              <tr key={i} className={l.isLate ? 'bg-danger/5' : ''}>
                <td className="td"><Link to={`/admin/commandes/${l.orderId}`} className="font-mono text-xs font-semibold text-gold-dark hover:underline">{l.number}</Link></td>
                <td className="td text-xs">{l.customer}</td>
                <td className="td text-sm">{l.product} <span className="text-xs text-ink-soft">({l.reference})</span></td>
                <td className="td">{l.receivedQty}/{l.quantity}</td>
                <td className="td text-xs">{l.arrival ?? '—'} {l.arrivalStepLabel && <Badge tone="gold">{l.arrivalStepLabel}</Badge>}</td>
                <td className="td text-xs">{l.shipmentStepLabel ?? '—'}</td>
                <td className="td text-xs">{l.estimatedDate ? formatDate(l.estimatedDate) : '—'}{l.isLate && <Badge tone="danger"> en retard</Badge>}</td>
                <td className="td"><Link to={`/admin/commandes/${l.orderId}`} className="btn-ghost !min-h-[30px] !px-2 text-xs">Voir</Link></td>
              </tr>
            ))}
          </DataTable>
        )
      )}

      {tab === 'stock' && (
        valuation.isLoading ? <PageLoader /> : !valuation.data ? null : (
          <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-3">
              {[['Unités en stock', String(valuation.data.totals.units)], ['Valeur au coût', formatFCFA(valuation.data.totals.costValue)], ['Valeur au prix de vente', formatFCFA(valuation.data.totals.retailValue)]].map(([l, v]) => (
                <div key={l} className="card p-4"><p className="text-xs uppercase tracking-wide text-ink-soft">{l}</p><p className="text-lg font-bold">{v}</p></div>
              ))}
            </div>
            <DataTable head={['Référence', 'Produit', 'Catégorie', 'Physique', 'Réservé', 'Coût unit.', 'Valeur coût', 'Valeur vente']}>
              {valuation.data.rows.map((r) => (
                <tr key={`${r.reference}-${r.sku}`}>
                  <td className="td font-mono text-xs">{r.sku}</td>
                  <td className="td text-sm">{r.product} <span className="text-xs text-ink-soft">({r.variant})</span></td>
                  <td className="td text-xs">{r.category || '—'}</td>
                  <td className="td">{r.onHand}</td>
                  <td className="td">{r.reserved}</td>
                  <td className="td">{formatFCFA(r.unitCost)}</td>
                  <td className="td font-semibold">{formatFCFA(r.costValue)}</td>
                  <td className="td">{formatFCFA(r.retailValue)}</td>
                </tr>
              ))}
            </DataTable>
          </div>
        )
      )}

      {tab === 'exports' && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {EXPORTS.filter((e) => e.entity !== 'audit-logs' || hasPermission(user, Permission.AUDIT_READ)).map((e) => (
            <div key={e.entity} className="card p-4">
              <p className="mb-3 font-semibold">{e.label}</p>
              {hasPermission(user, Permission.EXPORTS) ? (
                <div className="flex gap-2">
                  <button className="btn-primary !min-h-[34px] flex-1 text-xs" onClick={() => download(`/admin/exports/${e.entity}`, { format: 'xlsx', from: q.from || undefined, to: q.to || undefined }).catch((err) => notify(errorMessage(err), 'error'))}><Download className="h-4 w-4" /> Excel</button>
                  <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => download(`/admin/exports/${e.entity}`, { format: 'csv', from: q.from || undefined, to: q.to || undefined }).catch((err) => notify(errorMessage(err), 'error'))}>CSV</button>
                </div>
              ) : <p className="text-xs text-ink-soft">Permission « Exports » requise.</p>}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
