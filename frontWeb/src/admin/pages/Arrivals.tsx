import { formatDate, Paginated, STEP_LABELS, ShipmentStep, TRANSVERSE_STEPS } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { PackageSearch, Warehouse } from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import { Badge, DataTable, EmptyState, PageLoader, Pagination } from '@/admin/components/ui';
import { api } from '@/admin/lib/api';

interface ArrivalRow {
  id: number; reference: string; supplier: { name: string } | null; carrier: string | null;
  carrierTrackingNumber: string | null; currentStep: ShipmentStep; estimatedArrivalAt: string | null;
  receivedAt: string | null; createdAt: string; itemCount: number;
}

const ARRIVAL_STEPS_LIST = [
  ShipmentStep.ORDERED_FROM_SUPPLIER, ShipmentStep.SHIPPED_FROM_CHINA, ShipmentStep.IN_INTERNATIONAL_TRANSIT,
  ShipmentStep.ARRIVED_IN_GABON, ShipmentStep.RECEIVED_IN_STOCK,
];

export default function Arrivals() {
  const [params, setParams] = useSearchParams();
  const q = { q: params.get('q') ?? '', step: params.get('step') ?? '', page: Number(params.get('page') ?? 1), limit: 20 };
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v); else next.delete(k);
    next.delete('page');
    setParams(next, { replace: true });
  };
  const { data, isLoading } = useQuery({ queryKey: ['arrivals', q], queryFn: () => api<Paginated<ArrivalRow>>('/admin/arrivals', { query: q }), placeholderData: (p) => p });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Arrivages</h1>
        <Link to="/admin/precommandes" className="btn-primary"><PackageSearch className="h-4 w-4" /> Lignes en attente</Link>
      </div>
      <div className="card flex flex-wrap gap-3 p-4">
        <input className="input max-w-xs" placeholder="Référence, suivi, transporteur…" value={q.q} onChange={(e) => set('q', e.target.value)} />
        <select className="input max-w-xs" value={q.step} onChange={(e) => set('step', e.target.value)}>
          <option value="">Étape</option>
          {[...ARRIVAL_STEPS_LIST, ...TRANSVERSE_STEPS].map((s) => <option key={s} value={s}>{STEP_LABELS[s]}</option>)}
        </select>
      </div>
      {isLoading ? <PageLoader /> : !data?.data.length ? (
        <EmptyState icon={<Warehouse className="h-10 w-10" />} title="Aucun arrivage" text="Créez un arrivage depuis les lignes de précommande en attente." />
      ) : (
        <>
          <DataTable head={['Référence', 'Fournisseur', 'Transporteur', 'Étape', 'Arrivée estimée', 'Lignes', '']}>
            {data.data.map((a) => (
              <tr key={a.id} className="hover:bg-ivory/60">
                <td className="td font-mono text-xs font-semibold"><Link to={`/admin/arrivages/${a.id}`} className="text-gold-dark hover:underline">{a.reference}</Link></td>
                <td className="td">{a.supplier?.name ?? '—'}</td>
                <td className="td text-xs">{a.carrier ?? '—'}{a.carrierTrackingNumber ? ` · ${a.carrierTrackingNumber}` : ''}</td>
                <td className="td"><Badge tone={a.currentStep === ShipmentStep.RECEIVED_IN_STOCK ? 'success' : a.currentStep === ShipmentStep.DELAYED ? 'danger' : 'gold'}>{STEP_LABELS[a.currentStep]}</Badge></td>
                <td className="td text-xs">{a.estimatedArrivalAt ? formatDate(a.estimatedArrivalAt) : '—'}{a.receivedAt ? ` · reçu ${formatDate(a.receivedAt)}` : ''}</td>
                <td className="td">{a.itemCount}</td>
                <td className="td"><Link to={`/admin/arrivages/${a.id}`} className="btn-ghost !min-h-[30px] !px-2 text-xs">Ouvrir</Link></td>
              </tr>
            ))}
          </DataTable>
          <Pagination page={q.page} limit={q.limit} total={data.meta.total} onPage={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); }} />
        </>
      )}
    </div>
  );
}
