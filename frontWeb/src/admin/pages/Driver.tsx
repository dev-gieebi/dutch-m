import { formatFCFA, formatPhone, STEP_LABELS, ShipmentStep } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Banknote, CheckCircle2, ImagePlus, MapPin, Truck } from 'lucide-react';
import { useRef, useState } from 'react';
import { Alert, Badge, EmptyState, Field, Modal, PageLoader } from '@/admin/components/ui';
import { api, assetUrl, errorMessage } from '@/admin/lib/api';
import { useUiStore } from '@/admin/store/ui';
import { PlanningShipment } from './types';

interface DriverDelivery extends PlanningShipment {
  cashToCollect: number;
  itemsSummary: string;
}

export default function Driver() {
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [target, setTarget] = useState<DriverDelivery | null>(null);
  const [form, setForm] = useState<{ code: string; cashAmount: string; cashMethod: string; cashReference: string; comment: string; proofUrl: string | null }>({ code: '', cashAmount: '', cashMethod: 'CASH', cashReference: '', comment: '', proofUrl: null });
  const [error, setError] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const { data, isLoading } = useQuery({ queryKey: ['driver-deliveries', date], queryFn: () => api<DriverDelivery[]>('/admin/driver/deliveries', { query: { date } }) });

  const complete = useMutation({
    mutationFn: (b: Record<string, unknown>) => api(`/admin/driver/deliveries/${target!.id}/complete`, { method: 'POST', body: b }),
    onSuccess: () => { notify('Livraison confirmée — merci !'); setTarget(null); setError(null); qc.invalidateQueries({ queryKey: ['driver-deliveries'] }); },
    onError: (e) => setError(errorMessage(e)),
  });

  const uploadProof = async (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    try {
      const res = await api<{ url: string }>('/admin/uploads', { method: 'POST', body: fd });
      setForm((f) => ({ ...f, proofUrl: res.url }));
      notify('Photo ajoutée');
    } catch (e) {
      notify(errorMessage(e), 'error');
    }
  };

  const submit = () => {
    if (!target) return;
    complete.mutate({
      code: form.code,
      proofUrl: form.proofUrl ?? undefined,
      cashAmount: form.cashAmount ? Number(form.cashAmount) : undefined,
      cashMethod: form.cashAmount ? form.cashMethod : undefined,
      cashReference: form.cashReference || undefined,
      comment: form.comment || undefined,
    });
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Mes livraisons</h1>
        <input className="input w-auto" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      </div>
      {isLoading ? (
        <PageLoader />
      ) : !data?.length ? (
        <EmptyState icon={<Truck className="h-10 w-10" />} title="Aucune livraison" text="Vous n'avez aucune tournée prévue pour cette date." />
      ) : (
        <div className="grid gap-3">
          {data.map((s) => (
            <div key={s.id} className="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
              <div className="min-w-0 flex-1">
                <p className="font-mono text-xs font-semibold text-gold-dark">{s.orderNumber}</p>
                <p className="font-semibold">{s.contactName} · <a className="text-gold-dark" href={`tel:${s.contactPhone}`}>{formatPhone(s.contactPhone)}</a></p>
                <p className="text-sm text-ink-soft"><MapPin className="mr-1 inline h-4 w-4" />{s.address}</p>
                {s.itemsSummary && <p className="mt-1 text-xs text-ink-soft">{s.itemsSummary}</p>}
                {s.cashToCollect > 0 && <p className="mt-1 text-sm font-semibold text-warning">À encaisser : {formatFCFA(s.cashToCollect)}</p>}
              </div>
              <div className="flex items-center gap-2">
                {s.currentStep && <Badge tone="gold">{STEP_LABELS[s.currentStep]}</Badge>}
                {s.deliveredAt ? (
                  <Badge tone="success"><CheckCircle2 className="h-3.5 w-3.5" /> Livrée</Badge>
                ) : (
                  <button className="btn-primary !min-h-[40px] text-sm" onClick={() => { setTarget(s); setForm({ code: '', cashAmount: s.cashToCollect ? String(s.cashToCollect) : '', cashMethod: 'CASH', cashReference: '', comment: '', proofUrl: null }); setError(null); }}>Terminer</button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={!!target} onClose={() => setTarget(null)} title={`Livraison ${target?.orderNumber ?? ''}`}>
        <div className="space-y-3">
          <Field label="Code de remise (4 chiffres remis au client)" hint="Le client a reçu ce code par e-mail / SMS.">
            <input className="input text-center font-mono text-2xl tracking-[0.5em]" inputMode="numeric" maxLength={4} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.replace(/\D/g, '') })} autoFocus />
          </Field>
          {!!target?.cashToCollect && (
            <div className="grid gap-3 rounded-lg bg-warning/10 p-3 sm:grid-cols-2">
              <Field label={`Montant encaissé (${formatFCFA(target.cashToCollect)} attendus)`}>
                <input className="input" inputMode="numeric" value={form.cashAmount} onChange={(e) => setForm({ ...form, cashAmount: e.target.value.replace(/\D/g, '') })} />
              </Field>
              <Field label="Moyen">
                <select className="input" value={form.cashMethod} onChange={(e) => setForm({ ...form, cashMethod: e.target.value })}>
                  <option value="CASH">Espèces</option><option value="AIRTEL_MONEY">Airtel Money</option><option value="MOOV_MONEY">Moov Money</option>
                </select>
              </Field>
              <Field label="Référence (si mobile money)"><input className="input" value={form.cashReference} onChange={(e) => setForm({ ...form, cashReference: e.target.value })} /></Field>
            </div>
          )}
          <Field label="Preuve photo">
            <div className="flex items-center gap-3">
              <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={(e) => e.target.files?.[0] && uploadProof(e.target.files[0])} />
              <button type="button" className="btn-secondary !min-h-[38px] text-xs" onClick={() => fileRef.current?.click()}><ImagePlus className="h-4 w-4" /> Ajouter une photo</button>
              {form.proofUrl && <img src={assetUrl(form.proofUrl)} alt="Preuve" className="h-12 w-12 rounded object-cover" />}
            </div>
          </Field>
          <Field label="Commentaire"><input className="input" value={form.comment} onChange={(e) => setForm({ ...form, comment: e.target.value })} placeholder="Ex. remis au gardien" /></Field>
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary w-full" disabled={complete.isPending || form.code.length !== 4} onClick={submit}>
            <Banknote className="h-4 w-4" /> Confirmer la livraison
          </button>
        </div>
      </Modal>
    </div>
  );
}
