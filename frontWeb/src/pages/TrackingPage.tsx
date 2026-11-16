import {
  formatDate,
  formatFCFA,
  ItemType,
  normalizeGabonPhone,
  ORDER_STATUS_LABELS,
  OrderTrackingDto,
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  ShipmentStep,
  TrackingShipmentDto,
} from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, CircleDot, PackageSearch, Search } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { Alert, EmptyState, Field, PageSkeleton, Seo, Skeleton } from '@/components/ui';
import { api, assetUrl, errorMessage } from '@/lib/api';

function Timeline({ shipment }: { shipment: TrackingShipmentDto }) {
  const doneSteps = new Set(shipment.events.map((e) => e.step));
  const lastDone = shipment.events.length ? shipment.events[shipment.events.length - 1].step : null;
  return (
    <div className="card p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-serif text-lg font-semibold">
          {shipment.type === ItemType.LOCAL ? 'Livraison locale' : 'Précommande importée'}
        </h3>
        <span className="text-xs text-ink-soft">
          {shipment.estimatedDeliveryDate && <>Livraison estimée : {formatDate(shipment.estimatedDeliveryDate)}</>}
        </span>
      </div>
      {shipment.isDelayed && (
        <Alert tone="warning">
          <AlertTriangle className="mr-1 inline h-4 w-4" /> Un retard a été signalé{shipment.delayReason ? ` : ${shipment.delayReason}` : ''}. Nouvelle date estimée :{' '}
          {shipment.estimatedDeliveryDate ? formatDate(shipment.estimatedDeliveryDate) : 'en cours de calcul'}.
        </Alert>
      )}
      <ol className="mt-4 space-y-0" aria-label="Étapes de suivi">
        {shipment.steps.map(({ step, label }) => {
          const done = doneSteps.has(step);
          const event = shipment.events.find((e) => e.step === step);
          const current = lastDone === step;
          return (
            <li key={step} className="relative flex gap-3 pb-5 last:pb-0">
              <span className="flex flex-col items-center">
                {done ? (
                  <CheckCircle2 className={`h-6 w-6 shrink-0 ${current ? 'text-gold' : 'text-success'}`} />
                ) : (
                  <CircleDot className="h-6 w-6 shrink-0 text-sand" />
                )}
                {step !== shipment.steps[shipment.steps.length - 1].step && <span className={`mt-1 w-0.5 flex-1 ${done ? 'bg-success/50' : 'bg-sand'}`} />}
              </span>
              <div className="pb-1 pt-0.5">
                <p className={`text-sm ${done ? 'font-semibold' : 'text-ink-soft'}`}>{label}</p>
                {event && (
                  <>
                    <p className="text-xs text-ink-soft">{formatDate(event.occurredAt, true)}</p>
                    {event.publicComment && <p className="mt-1 text-xs italic text-ink-soft">{event.publicComment}</p>}
                    {event.proofUrl && <img src={assetUrl(event.proofUrl)} alt="Preuve" className="mt-2 h-16 w-16 rounded-lg object-cover" loading="lazy" />}
                  </>
                )}
              </div>
            </li>
          );
        })}
      </ol>
      <ul className="mt-4 flex flex-wrap gap-2 border-t border-sand pt-3">
        {shipment.items.map((i, k) => (
          <li key={k} className="flex items-center gap-2 rounded-lg bg-sand/60 px-2 py-1 text-xs">
            {i.imageUrl && <img src={assetUrl(i.imageUrl)} alt="" className="h-6 w-6 rounded object-cover" loading="lazy" />}
            {i.name} {i.variantName ? `· ${i.variantName}` : ''} × {i.quantity}
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function TrackingPage() {
  const { token } = useParams();
  const [params] = useSearchParams();
  const [number, setNumber] = useState(params.get('number') ?? '');
  const [phone, setPhone] = useState('');
  const [lookup, setLookup] = useState<{ number: string; phone: string } | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const tokenQuery = useQuery({
    queryKey: ['track-token', token],
    queryFn: () => api<OrderTrackingDto>(`/orders/track/${token}`),
    enabled: !!token,
    retry: false,
  });
  const formQuery = useQuery({
    queryKey: ['track-form', lookup?.number],
    queryFn: () => api<OrderTrackingDto>('/orders/track', { query: lookup! }),
    enabled: !!lookup && !token,
    retry: false,
  });
  const order = token ? tokenQuery.data : formQuery.data;
  const isLoading = token ? tokenQuery.isLoading : formQuery.isLoading;
  const error = token ? tokenQuery.error : formQuery.error;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!/^AMO-\d{4}-\d{6}$/i.test(number.trim())) return setFormError('Format attendu : AMO-2026-000123');
    if (!normalizeGabonPhone(phone)) return setFormError('Numéro invalide (format : +241…)');
    setFormError(null);
    setLookup({ number: number.trim().toUpperCase(), phone: normalizeGabonPhone(phone)! });
  };

  return (
    <div className="container-page max-w-3xl py-8">
      <Seo title="Suivi de commande" description="Suivez votre commande DutCh’M étape par étape." />
      <h1 className="section-title mb-6 !text-3xl">Suivi de commande</h1>
      {!token && (
        <form onSubmit={submit} className="card mb-6 space-y-4 p-5">
          <Field label="Numéro de commande" error={undefined}>
            <input className="input font-mono" value={number} onChange={(e) => setNumber(e.target.value)} placeholder="AMO-2026-000123" />
          </Field>
          <Field label="Téléphone utilisé à la commande">
            <input className="input" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+241 77 12 34 56" />
          </Field>
          {formError && <p className="field-error" role="alert">{formError}</p>}
          <button className="btn-primary w-full sm:w-auto"><Search className="h-4 w-4" /> Rechercher</button>
          <p className="text-xs text-ink-soft">Les deux informations doivent correspondre exactement à votre commande.</p>
        </form>
      )}
      {isLoading && (
        <div className="space-y-4"><Skeleton className="h-48" /><Skeleton className="h-64" /></div>
      )}
      {error && !isLoading && (
        <Alert tone="error">{errorMessage(error)} Aucune information d'une autre commande ne sera affichée.</Alert>
      )}
      {order && (
        <div className="space-y-6">
          <div className="card flex flex-wrap items-center justify-between gap-3 p-5">
            <div>
              <p className="font-mono text-sm font-semibold">{order.number}</p>
              <p className="text-xs text-ink-soft">Passée le {formatDate(order.createdAt, true)} · {order.customerFirstName}</p>
            </div>
            <div className="flex gap-2 text-xs">
              <span className="badge bg-sand px-3 py-1.5">{ORDER_STATUS_LABELS[order.status]}</span>
              <span className="badge bg-gold/15 px-3 py-1.5 text-gold-dark">{PAYMENT_STATUS_LABELS[order.paymentStatus]}</span>
            </div>
          </div>
          {order.pendingPaymentId && (
            <Alert tone="warning">Un paiement de {formatFCFA(order.balanceDue || order.depositAmount)} est en attente.{' '}
              <Link to={`/paiement/${order.pendingPaymentId}?token=${token ?? ''}`} className="underline">Reprendre le paiement</Link>
            </Alert>
          )}
          <dl className="card grid grid-cols-2 gap-3 p-5 text-sm sm:grid-cols-4">
            <div><dt className="text-ink-soft">Total</dt><dd className="font-semibold">{formatFCFA(order.total)}</dd></div>
            <div><dt className="text-ink-soft">Payé</dt><dd className="font-semibold text-success">{formatFCFA(order.amountPaid)}</dd></div>
            <div><dt className="text-ink-soft">Reste dû</dt><dd className="font-semibold">{formatFCFA(order.balanceDue)}</dd></div>
            <div><dt className="text-ink-soft">Paiement</dt><dd className="font-semibold">{PAYMENT_METHOD_LABELS[order.paymentMethod]}</dd></div>
          </dl>
          {order.shipments.map((s) => <Timeline key={s.id} shipment={s} />)}
        </div>
      )}
      {!token && !lookup && !isLoading && !order && (
        <EmptyState icon={<PackageSearch className="h-10 w-10" />} title="Retrouvez votre commande" text="Entrez votre numéro de commande et le téléphone utilisé lors de l'achat." />
      )}
    </div>
  );
}
