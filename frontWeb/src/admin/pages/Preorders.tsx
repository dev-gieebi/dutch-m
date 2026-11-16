import { formatDate, Permission } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PackagePlus, PackageSearch } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Alert, Badge, DataTable, EmptyState, Field, Modal, PageLoader } from '@/admin/components/ui';
import { api, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';

interface PendingLine { itemId: number; orderId: number; orderNumber: string; orderDate: string; customerName: string; productName: string; reference: string; variantName: string | null; quantity: number; supplierId: number | null; supplierName: string }
interface PendingGroup { supplierId: number | null; supplierName: string; productName: string; reference: string; variantName: string | null; totalQuantity: number; itemIds: number[] }
interface Supplier { id: number; name: string }

export default function Preorders() {
  const user = useAuthStore((s) => s.user);
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState({ supplierId: '', carrier: '', carrierTrackingNumber: '', estimatedArrivalAt: '', publicComment: '' });
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({ queryKey: ['preorders-pending'], queryFn: () => api<{ lines: PendingLine[]; groups: PendingGroup[] }>('/admin/preorders/pending') });
  const { data: suppliers } = useQuery({ queryKey: ['suppliers-admin'], queryFn: () => api<Supplier[]>('/admin/suppliers'), staleTime: 5 * 60_000 });
  const canWrite = hasPermission(user, Permission.ARRIVALS_WRITE);

  const create = useMutation({
    mutationFn: () => api<{ id: number }>('/admin/arrivals', {
      method: 'POST',
      body: { itemIds: [...selected], supplierId: form.supplierId ? Number(form.supplierId) : null, carrier: form.carrier || null, carrierTrackingNumber: form.carrierTrackingNumber || null, estimatedArrivalAt: form.estimatedArrivalAt || null, publicComment: form.publicComment || undefined },
    }),
    onSuccess: (a) => { notify('Arrivage créé — les clients ont été notifiés'); setCreateOpen(false); setSelected(new Set()); qc.invalidateQueries({ queryKey: ['preorders-pending', 'arrivals'] }); navigate(`/admin/arrivages/${a.id}`); },
    onError: (e) => setError(errorMessage(e)),
  });

  const toggle = (ids: number[]) => setSelected((s) => { const n = new Set(s); const all = ids.every((id) => n.has(id)); ids.forEach((id) => (all ? n.delete(id) : n.add(id))); return n; });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl">Précommandes en attente</h1>
          <p className="text-sm text-ink-soft">Lignes confirmées et payées à regrouper dans un arrivage fournisseur.</p>
        </div>
        {canWrite && <button className="btn-primary" disabled={!selected.size} onClick={() => { setCreateOpen(true); setError(null); }}><PackagePlus className="h-4 w-4" /> Créer un arrivage ({selected.size})</button>}
      </div>
      {isLoading ? <PageLoader /> : !data?.groups.length ? (
        <EmptyState icon={<PackageSearch className="h-10 w-10" />} title="Aucune précommande en attente" text="Les lignes « Sur commande » payées apparaîtront ici pour être groupées." />
      ) : (
        <div className="space-y-4">
          {data.groups.map((g) => (
            <div key={`${g.supplierId}|${g.reference}`} className="card p-4">
              <div className="mb-2 flex flex-wrap items-center gap-2">
                {canWrite && <input type="checkbox" className="h-4 w-4 accent-gold" checked={g.itemIds.every((id) => selected.has(id))} onChange={() => toggle(g.itemIds)} aria-label={`Tout sélectionner ${g.reference}`} />}
                <p className="font-semibold">{g.productName}{g.variantName ? ` · ${g.variantName}` : ''}</p>
                <Badge tone="muted">{g.reference}</Badge>
                <Badge tone="gold">{g.supplierName}</Badge>
                <span className="ml-auto text-sm font-semibold">Total : {g.totalQuantity}</span>
              </div>
              <DataTable head={canWrite ? ['', 'Commande', 'Client', 'Qté', 'Date'] : ['Commande', 'Client', 'Qté', 'Date']}>
                {data.lines.filter((l) => g.itemIds.includes(l.itemId)).map((l) => (
                  <tr key={l.itemId}>
                    {canWrite && <td className="td"><input type="checkbox" className="h-4 w-4 accent-gold" checked={selected.has(l.itemId)} onChange={() => toggle([l.itemId])} aria-label="Sélectionner" /></td>}
                    <td className="td"><Link to={`/admin/commandes/${l.orderId}`} className="font-mono text-xs font-semibold text-gold-dark hover:underline">{l.orderNumber}</Link></td>
                    <td className="td">{l.customerName}</td>
                    <td className="td">{l.quantity}</td>
                    <td className="td text-xs">{formatDate(l.orderDate)}</td>
                  </tr>
                ))}
              </DataTable>
            </div>
          ))}
        </div>
      )}
      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Nouvel arrivage">
        <div className="space-y-3">
          <p className="text-sm text-ink-soft">{selected.size} ligne(s) seront rattachées à cet arrivage et leurs commandes passeront à l'étape « Commandé au fournisseur ».</p>
          <Field label="Fournisseur"><select className="input" value={form.supplierId} onChange={(e) => setForm({ ...form, supplierId: e.target.value })}>
            <option value="">—</option>
            {suppliers?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Transporteur"><input className="input" value={form.carrier} onChange={(e) => setForm({ ...form, carrier: e.target.value })} placeholder="Ex. Afrijet Cargo" /></Field>
            <Field label="N° de suivi transporteur"><input className="input" value={form.carrierTrackingNumber} onChange={(e) => setForm({ ...form, carrierTrackingNumber: e.target.value })} /></Field>
          </div>
          <Field label="Arrivée estimée"><input className="input" type="date" value={form.estimatedArrivalAt} onChange={(e) => setForm({ ...form, estimatedArrivalAt: e.target.value })} /></Field>
          <Field label="Commentaire client"><input className="input" value={form.publicComment} onChange={(e) => setForm({ ...form, publicComment: e.target.value })} placeholder="Ex. commandés au fournisseur ce jour" /></Field>
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary w-full" disabled={create.isPending} onClick={() => create.mutate()}><PackagePlus className="h-4 w-4" /> Créer l'arrivage</button>
        </div>
      </Modal>
    </div>
  );
}
