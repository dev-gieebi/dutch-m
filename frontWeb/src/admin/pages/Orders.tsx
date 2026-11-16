import {
  DeliveryZoneDto,
  formatDate,
  formatFCFA,
  ORDER_STATUS_LABELS,
  OrderStatus,
  Paginated,
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  PaymentMethod,
  PaymentStatus,
} from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { PackageSearch, Plus } from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import { DataTable, EmptyState, PageLoader, Pagination, StatusBadge } from '@/admin/components/ui';
import { api } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { Permission } from '@/shared';

interface OrderRow {
  id: number; number: string; contactName: string; contactPhone: string; createdAt: string;
  status: OrderStatus; paymentStatus: PaymentStatus; paymentMethod: PaymentMethod;
  orderType: string; total: number; balanceDue: number; zoneName: string | null;
}

const TYPE_LABELS: Record<string, string> = { LOCAL: 'Stock', PREORDER: 'Précommande', MIXED: 'Mixte' };

export default function Orders() {
  const user = useAuthStore((s) => s.user);
  const [params, setParams] = useSearchParams();
  const q = {
    q: params.get('q') ?? '',
    status: params.get('status') ?? '',
    paymentStatus: params.get('paymentStatus') ?? '',
    paymentMethod: params.get('paymentMethod') ?? '',
    orderType: params.get('orderType') ?? '',
    zoneId: params.get('zoneId') ?? '',
    preset: params.get('preset') ?? '',
    from: params.get('from') ?? '',
    to: params.get('to') ?? '',
    page: Number(params.get('page') ?? 1),
    limit: 25,
  };
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v); else next.delete(k);
    next.delete('page');
    setParams(next, { replace: true });
  };
  const { data: zones } = useQuery({ queryKey: ['zones-admin'], queryFn: () => api<DeliveryZoneDto[]>('/admin/delivery/zones'), staleTime: 10 * 60_000 });
  const { data, isLoading } = useQuery({ queryKey: ['orders', q], queryFn: () => api<Paginated<OrderRow>>('/admin/orders', { query: q }), placeholderData: (p) => p });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Commandes</h1>
        <div className="flex gap-2">
          {hasPermission(user, Permission.ORDERS_WRITE) && <Link to="/admin/commandes/nouvelle" className="btn-primary"><Plus className="h-4 w-4" /> Commande manuelle</Link>}
        </div>
      </div>
      <div className="flex flex-wrap gap-1 rounded-lg bg-sand/50 p-1 text-xs">
        {[['', 'Toutes'], ['todo', 'À traiter'], ['pending_payment', 'Paiement en attente'], ['awaiting_cash', 'Encaissements'], ['delayed', 'En retard']].map(([v, l]) => (
          <button key={v} onClick={() => set('preset', v)} className={`rounded-md px-3 py-1.5 font-semibold ${q.preset === v ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}>{l}</button>
        ))}
      </div>
      <div className="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
        <input className="input" placeholder="N°, nom, téléphone…" value={q.q} onChange={(e) => set('q', e.target.value)} />
        <select className="input" value={q.status} onChange={(e) => set('status', e.target.value)}>
          <option value="">Statut</option>
          {Object.entries(ORDER_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select className="input" value={q.paymentStatus} onChange={(e) => set('paymentStatus', e.target.value)}>
          <option value="">Paiement</option>
          {Object.entries(PAYMENT_STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select className="input" value={q.paymentMethod} onChange={(e) => set('paymentMethod', e.target.value)}>
          <option value="">Moyen</option>
          {Object.entries(PAYMENT_METHOD_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select className="input" value={q.orderType} onChange={(e) => set('orderType', e.target.value)}>
          <option value="">Type</option>
          {Object.entries(TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select className="input" value={q.zoneId} onChange={(e) => set('zoneId', e.target.value)}>
          <option value="">Zone</option>
          {zones?.map((z) => <option key={z.id} value={z.id}>{z.name}</option>)}
        </select>
        <input className="input" type="date" value={q.from} onChange={(e) => set('from', e.target.value)} aria-label="Du" />
        <input className="input" type="date" value={q.to} onChange={(e) => set('to', e.target.value)} aria-label="Au" />
      </div>
      {isLoading ? (
        <PageLoader />
      ) : !data?.data.length ? (
        <EmptyState icon={<PackageSearch className="h-10 w-10" />} title="Aucune commande" text="Aucune commande ne correspond à ces filtres." />
      ) : (
        <>
          <DataTable head={['N°', 'Client', 'Date', 'Type', 'Statut', 'Paiement', 'Zone', 'Total', '']}>
            {data.data.map((o) => (
              <tr key={o.id} className="hover:bg-ivory/60">
                <td className="td font-mono text-xs font-semibold"><Link to={`/admin/commandes/${o.id}`} className="text-gold-dark hover:underline">{o.number}</Link></td>
                <td className="td"><p className="font-medium">{o.contactName}</p><p className="text-xs text-ink-soft">{o.contactPhone}</p></td>
                <td className="td text-xs">{formatDate(o.createdAt, true)}</td>
                <td className="td text-xs">{TYPE_LABELS[o.orderType] ?? o.orderType}</td>
                <td className="td"><StatusBadge value={o.status} /></td>
                <td className="td"><StatusBadge value={o.paymentStatus} /></td>
                <td className="td text-xs">{o.zoneName ?? '—'}</td>
                <td className="td font-semibold">{formatFCFA(o.total)}</td>
                <td className="td"><Link to={`/admin/commandes/${o.id}`} className="btn-ghost !min-h-[30px] !px-2 text-xs">Ouvrir</Link></td>
              </tr>
            ))}
          </DataTable>
          <Pagination page={q.page} limit={q.limit} total={data.meta.total} onPage={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); }} />
        </>
      )}
    </div>
  );
}
