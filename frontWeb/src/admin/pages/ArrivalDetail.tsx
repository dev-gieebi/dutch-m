import { formatDate, formatFCFA, Permission, STEP_LABELS, ShipmentStep } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, FileText, ImagePlus, PackageCheck, Pencil, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Alert, Badge, DataTable, Field, Modal, PageLoader } from '@/admin/components/ui';
import { api, assetUrl, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';

interface ArrivalItem { id: number; productName: string; reference: string; variantName: string | null; quantity: number; receivedQty: number; orderId: number; order: { id: number; number: string; contactName: string } }
interface ArrivalEvent { id: number; step: ShipmentStep; label: string; publicComment: string | null; internalNote: string | null; occurredAt: string; adminUser: { fullName: string } | null }
interface ArrivalDoc { id: number; name: string; url: string; mimeType: string }
interface ArrivalData {
  id: number; reference: string; supplier: { id: number; name: string } | null; carrier: string | null; carrierTrackingNumber: string | null;
  currentStep: ShipmentStep; shippedAt: string | null; estimatedArrivalAt: string | null; arrivedAt: string | null; receivedAt: string | null;
  transportCost: number; customsCost: number; notes: string | null; carrierProvider: string;
  items: ArrivalItem[]; events: ArrivalEvent[]; documents: ArrivalDoc[]; steps: { step: ShipmentStep; label: string }[];
  orders: { id: number; number: string; customerName: string; status: string }[];
}
interface PendingLine { itemId: number; orderNumber: string; customerName: string; productName: string; reference: string; variantName: string | null; quantity: number; supplierId: number | null }

type ModalKind = 'edit' | 'step' | 'receive' | 'addItems' | null;

export default function ArrivalDetail() {
  const { id = '' } = useParams();
  const user = useAuthStore((s) => s.user);
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [modal, setModal] = useState<ModalKind>(null);
  const [form, setForm] = useState<Record<string, string | boolean>>({});
  const [received, setReceived] = useState<Record<number, number>>({});
  const [picked, setPicked] = useState<Set<number>>(new Set());
  const [error, setError] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const { data: a, isLoading } = useQuery({ queryKey: ['arrival', id], queryFn: () => api<ArrivalData>(`/admin/arrivals/${id}`) });
  const { data: pending } = useQuery({ queryKey: ['preorders-pending'], queryFn: () => api<{ lines: PendingLine[] }>('/admin/preorders/pending'), enabled: modal === 'addItems' });
  const refresh = () => qc.invalidateQueries({ queryKey: ['arrival', id] });
  const canWrite = hasPermission(user, Permission.ARRIVALS_WRITE);

  const mutate = useMutation({
    mutationFn: ({ path, body, method = 'POST' }: { path: string; body?: unknown; method?: string }) => api(path, { method, body }),
    onSuccess: (d: unknown) => {
      const msg = (d as { message?: string })?.message;
      notify(msg ?? 'Enregistré'); setModal(null); setError(null); setForm({}); setPicked(new Set());
      refresh(); qc.invalidateQueries({ queryKey: ['arrivals', 'preorders-pending'] });
    },
    onError: (e) => setError(errorMessage(e)),
  });
  const open = (kind: ModalKind) => { setModal(kind); setError(null); setForm({}); };

  const uploadDoc = async (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    try {
      const doc = await api<{ url: string; name: string; mimeType: string }>('/admin/uploads?kind=document', { method: 'POST', body: fd });
      await mutate.mutateAsync({ path: `/admin/arrivals/${id}/documents`, body: doc });
    } catch (e) {
      notify(errorMessage(e), 'error');
    }
  };

  if (isLoading || !a) return <PageLoader />;
  const done = a.currentStep === ShipmentStep.RECEIVED_IN_STOCK || a.receivedAt;
  const nextStep = a.steps.find((s) => !a.events.some((e) => e.step === s.step));

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/arrivages" className="btn-ghost !px-2"><ArrowLeft className="h-4 w-4" /></Link>
        <h1 className="font-mono text-xl font-bold">{a.reference}</h1>
        <Badge tone={done ? 'success' : 'gold'}>{STEP_LABELS[a.currentStep]}</Badge>
        <div className="ml-auto flex flex-wrap gap-2">
          {canWrite && !done && nextStep && <button className="btn-primary !min-h-[36px] text-xs" onClick={() => { open('step'); setForm({ step: nextStep.step }); }}>Étape : {nextStep.label}</button>}
          {canWrite && !done && <button className="btn-secondary !min-h-[36px] text-xs" onClick={() => { open('edit'); setForm({ carrier: a.carrier ?? '', carrierTrackingNumber: a.carrierTrackingNumber ?? '', shippedAt: a.shippedAt?.slice(0, 10) ?? '', estimatedArrivalAt: a.estimatedArrivalAt?.slice(0, 10) ?? '', transportCost: String(a.transportCost), customsCost: String(a.customsCost), notes: a.notes ?? '' }); }}><Pencil className="h-4 w-4" /> Infos</button>}
          {canWrite && a.currentStep === ShipmentStep.ARRIVED_IN_GABON && (
            <button className="btn-primary !min-h-[36px] text-xs" onClick={() => { setReceived(Object.fromEntries(a.items.map((i) => [i.id, i.quantity - i.receivedQty]))); open('receive'); }}>
              <PackageCheck className="h-4 w-4" /> Réceptionner
            </button>
          )}
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <div className="card p-4 text-sm">
            <dl className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
              <div className="flex justify-between"><dt className="text-ink-soft">Fournisseur</dt><dd>{a.supplier?.name ?? '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-ink-soft">Transporteur</dt><dd>{a.carrier ?? '—'}{a.carrierTrackingNumber ? ` · ${a.carrierTrackingNumber}` : ''}</dd></div>
              <div className="flex justify-between"><dt className="text-ink-soft">Expédié le</dt><dd>{a.shippedAt ? formatDate(a.shippedAt) : '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-ink-soft">Arrivée estimée</dt><dd>{a.estimatedArrivalAt ? formatDate(a.estimatedArrivalAt) : '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-ink-soft">Transport</dt><dd>{formatFCFA(a.transportCost)}</dd></div>
              <div className="flex justify-between"><dt className="text-ink-soft">Douane</dt><dd>{formatFCFA(a.customsCost)}</dd></div>
            </dl>
            {a.notes && <p className="mt-2 border-t border-sand pt-2 text-xs text-ink-soft">{a.notes}</p>}
          </div>

          <div className="card p-4">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="font-semibold">Lignes ({a.items.length})</h2>
              {canWrite && !done && <button className="btn-secondary !min-h-[32px] text-xs" onClick={() => open('addItems')}><Plus className="h-4 w-4" /> Ajouter des lignes</button>}
            </div>
            <DataTable head={['Produit', 'Commande', 'Client', 'Qté', 'Reçu', '']}>
              {a.items.map((i) => (
                <tr key={i.id}>
                  <td className="td"><p className="font-medium">{i.productName}</p><p className="text-xs text-ink-soft">{i.reference}{i.variantName ? ` · ${i.variantName}` : ''}</p></td>
                  <td className="td"><Link to={`/admin/commandes/${i.orderId}`} className="font-mono text-xs text-gold-dark hover:underline">{i.order.number}</Link></td>
                  <td className="td text-xs">{i.order.contactName}</td>
                  <td className="td">{i.quantity}</td>
                  <td className="td"><Badge tone={i.receivedQty >= i.quantity ? 'success' : i.receivedQty > 0 ? 'warning' : 'muted'}>{i.receivedQty}</Badge></td>
                  <td className="td">{canWrite && !done && <button className="btn-ghost !min-h-[30px] !px-2 text-xs text-danger" onClick={() => confirm('Retirer cette ligne de l\'arrivage ?') && mutate.mutate({ path: `/admin/arrivals/${id}/items/remove`, body: { itemIds: [i.id] } })}>Retirer</button>}</td>
                </tr>
              ))}
            </DataTable>
          </div>

          <div className="card p-4">
            <h2 className="mb-3 font-semibold">Commandes concernées</h2>
            <div className="flex flex-wrap gap-2">
              {a.orders.map((o) => <Link key={o.id} to={`/admin/commandes/${o.id}`} className="badge bg-sand text-ink hover:bg-gold/20">{o.number} · {o.customerName}</Link>)}
            </div>
          </div>
        </div>

        <div className="space-y-4">
          <div className="card p-4">
            <h2 className="mb-3 font-semibold">Suivi du transport</h2>
            <ol className="space-y-1.5">
              {a.steps.map(({ step, label }) => {
                const ev = a.events.find((e) => e.step === step);
                return (
                  <li key={step} className="flex items-start gap-2 text-sm">
                    <span className={`mt-1 h-2.5 w-2.5 shrink-0 rounded-full ${ev ? 'bg-success' : 'bg-sand'}`} />
                    <div>
                      <span className={ev ? 'font-medium' : 'text-ink-soft'}>{label}</span>
                      {ev && <p className="text-xs text-ink-soft">{formatDate(ev.occurredAt, true)}{ev.adminUser ? ` · ${ev.adminUser.fullName}` : ''}{ev.publicComment ? ` · « ${ev.publicComment} »` : ''}</p>}
                    </div>
                  </li>
                );
              })}
              {a.events.filter((e) => !a.steps.some((s) => s.step === e.step)).map((e) => (
                <li key={e.id} className="flex items-start gap-2 text-sm">
                  <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-warning" />
                  <div><span className="font-medium">{e.label}</span><p className="text-xs text-ink-soft">{formatDate(e.occurredAt, true)}{e.publicComment ? ` · « ${e.publicComment} »` : ''}</p></div>
                </li>
              ))}
            </ol>
            {canWrite && <button className="btn-secondary mt-3 !min-h-[34px] w-full text-xs" onClick={() => open('step')}>Enregistrer une étape libre</button>}
          </div>
          <div className="card p-4">
            <h2 className="mb-3 font-semibold">Documents</h2>
            <ul className="space-y-1.5">
              {a.documents.map((d) => (
                <li key={d.id} className="flex items-center justify-between gap-2 text-sm">
                  <a href={assetUrl(d.url)} target="_blank" rel="noreferrer" className="flex items-center gap-2 text-gold-dark hover:underline"><FileText className="h-4 w-4" /> {d.name}</a>
                  {canWrite && <button onClick={() => confirm('Supprimer ce document ?') && mutate.mutate({ path: `/admin/arrival-documents/${d.id}`, method: 'DELETE' })} aria-label="Supprimer"><Trash2 className="h-4 w-4 text-danger" /></button>}
                </li>
              ))}
              {!a.documents.length && <li className="text-xs text-ink-soft">Aucun document (BL, facture fournisseur…)</li>}
            </ul>
            {canWrite && (
              <>
                <button className="btn-secondary mt-3 !min-h-[34px] w-full text-xs" onClick={() => fileRef.current?.click()}><ImagePlus className="h-4 w-4" /> Joindre un document</button>
                <input ref={fileRef} type="file" accept="image/*,.pdf" className="hidden" onChange={(e) => { if (e.target.files?.[0]) void uploadDoc(e.target.files[0]); e.target.value = ''; }} />
              </>
            )}
          </div>
        </div>
      </div>

      <Modal open={modal === 'edit'} onClose={() => setModal(null)} title="Informations de l'arrivage">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Transporteur"><input className="input" value={String(form.carrier ?? '')} onChange={(e) => setForm({ ...form, carrier: e.target.value })} /></Field>
          <Field label="N° de suivi"><input className="input" value={String(form.carrierTrackingNumber ?? '')} onChange={(e) => setForm({ ...form, carrierTrackingNumber: e.target.value })} /></Field>
          <Field label="Expédié le"><input className="input" type="date" value={String(form.shippedAt ?? '')} onChange={(e) => setForm({ ...form, shippedAt: e.target.value })} /></Field>
          <Field label="Arrivée estimée"><input className="input" type="date" value={String(form.estimatedArrivalAt ?? '')} onChange={(e) => setForm({ ...form, estimatedArrivalAt: e.target.value })} /></Field>
          <Field label="Transport (FCFA)"><input className="input" inputMode="numeric" value={String(form.transportCost ?? '')} onChange={(e) => setForm({ ...form, transportCost: e.target.value.replace(/\D/g, '') })} /></Field>
          <Field label="Douane (FCFA)"><input className="input" inputMode="numeric" value={String(form.customsCost ?? '')} onChange={(e) => setForm({ ...form, customsCost: e.target.value.replace(/\D/g, '') })} /></Field>
          <Field label="Notes"><textarea className="input sm:col-span-2" rows={2} value={String(form.notes ?? '')} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary sm:col-span-2" disabled={mutate.isPending} onClick={() => mutate.mutate({ path: `/admin/arrivals/${id}`, method: 'PATCH', body: { carrier: form.carrier || null, carrierTrackingNumber: form.carrierTrackingNumber || null, shippedAt: form.shippedAt || null, estimatedArrivalAt: form.estimatedArrivalAt || null, transportCost: Number(form.transportCost ?? 0), customsCost: Number(form.customsCost ?? 0), notes: form.notes || null } })}>Enregistrer</button>
        </div>
      </Modal>

      <Modal open={modal === 'step'} onClose={() => setModal(null)} title="Étape de l'arrivage">
        <div className="space-y-3">
          <Field label="Étape"><select className="input" value={String(form.step ?? '')} onChange={(e) => setForm({ ...form, step: e.target.value })}>
            <option value="">—</option>
            {a.steps.map((s) => <option key={s.step} value={s.step}>{s.label}</option>)}
            <option value={ShipmentStep.DELAYED}>{STEP_LABELS[ShipmentStep.DELAYED]} (transverse)</option>
          </select></Field>
          <Field label="Commentaire client"><input className="input" value={String(form.publicComment ?? '')} onChange={(e) => setForm({ ...form, publicComment: e.target.value })} /></Field>
          <Field label="Note interne"><input className="input" value={String(form.internalNote ?? '')} onChange={(e) => setForm({ ...form, internalNote: e.target.value })} /></Field>
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary w-full" disabled={mutate.isPending || !form.step} onClick={() => mutate.mutate({ path: `/admin/arrivals/${id}/steps`, body: { step: form.step, publicComment: form.publicComment || undefined, internalNote: form.internalNote || undefined } })}>Enregistrer</button>
        </div>
      </Modal>

      <Modal open={modal === 'receive'} onClose={() => setModal(null)} title="Réceptionner l'arrivage" wide>
        <div className="space-y-3">
          <p className="text-sm text-ink-soft">Les quantités reçues seront entrées en stock et les expéditions client passeront en préparation. Une ligne incomplète sera signalée.</p>
          <DataTable head={['Produit', 'Commande', 'Attendu', 'Reçu']}>
            {a.items.map((i) => (
              <tr key={i.id}>
                <td className="td text-sm">{i.productName} <span className="text-xs text-ink-soft">({i.reference})</span></td>
                <td className="td font-mono text-xs">{i.order.number}</td>
                <td className="td">{i.quantity - i.receivedQty}</td>
                <td className="td"><input className="input !h-9 w-20" inputMode="numeric" value={received[i.id] ?? 0} onChange={(e) => setReceived({ ...received, [i.id]: Number(e.target.value.replace(/\D/g, '')) })} /></td>
              </tr>
            ))}
          </DataTable>
          <Field label="Commentaire client"><input className="input" value={String(form.publicComment ?? '')} onChange={(e) => setForm({ ...form, publicComment: e.target.value })} /></Field>
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary w-full" disabled={mutate.isPending} onClick={() => mutate.mutate({ path: `/admin/arrivals/${id}/reception`, body: { lines: a.items.map((i) => ({ itemId: i.id, receivedQty: received[i.id] ?? 0 })), publicComment: form.publicComment || undefined } })}>Confirmer la réception</button>
        </div>
      </Modal>

      <Modal open={modal === 'addItems'} onClose={() => setModal(null)} title="Ajouter des lignes" wide>
        <div className="space-y-3">
          {pending?.lines.length ? (
            <DataTable head={['', 'Commande', 'Client', 'Produit', 'Qté']}>
              {pending.lines.map((l) => (
                <tr key={l.itemId}>
                  <td className="td"><input type="checkbox" className="h-4 w-4 accent-gold" checked={picked.has(l.itemId)} onChange={() => setPicked((s) => { const n = new Set(s); if (n.has(l.itemId)) n.delete(l.itemId); else n.add(l.itemId); return n; })} aria-label="Sélectionner" /></td>
                  <td className="td font-mono text-xs">{l.orderNumber}</td>
                  <td className="td text-xs">{l.customerName}</td>
                  <td className="td text-sm">{l.productName} <span className="text-xs text-ink-soft">({l.reference}{l.variantName ? ` · ${l.variantName}` : ''})</span></td>
                  <td className="td">{l.quantity}</td>
                </tr>
              ))}
            </DataTable>
          ) : <Alert tone="info">Aucune ligne en attente disponible.</Alert>}
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary w-full" disabled={mutate.isPending || !picked.size} onClick={() => mutate.mutate({ path: `/admin/arrivals/${id}/items`, body: { itemIds: [...picked] } })}>Ajouter {picked.size ? `(${picked.size})` : ''}</button>
        </div>
      </Modal>
    </div>
  );
}
