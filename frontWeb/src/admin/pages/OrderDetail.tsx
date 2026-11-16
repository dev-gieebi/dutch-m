import {
  formatDate,
  formatFCFA,
  formatPhone,
  ItemType,
  NotificationTemplateCode,
  OrderStatus,
  PaymentMethod,
  PaymentStatus,
  Permission,
  PAYMENT_METHOD_LABELS,
  STEP_LABELS,
  ShipmentStep,
} from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Banknote, BellRing, CalendarClock, CheckCircle2, KeyRound, MessageSquare, Printer, ShieldCheck, Truck, Undo2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Alert, Badge, Field, Modal, Money, PageLoader, StatusBadge } from '@/admin/components/ui';
import { api, assetUrl, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';
import { AdminUser, DriverOption } from './types';

interface OrderItemRow {
  id: number; productName: string; reference: string; variantName: string | null; imageUrl: string | null;
  unitPrice: number; quantity: number; lineTotal: number; depositAmount: number; leadTimeDays: number;
  itemType: ItemType; arrivalId: number | null; receivedQty: number; stockReserved: boolean;
}
interface ShipmentEventRow { id: number; step: ShipmentStep; label: string; publicComment: string | null; internalNote: string | null; proofUrl: string | null; occurredAt: string; adminUser: { fullName: string } | null }
interface ShipmentRow {
  id: number; type: ItemType; currentStep: ShipmentStep | null; estimatedDeliveryDate: string | null;
  shippingFee: number; deliveryCode: string; driver: { id: number; fullName: string; phone: string | null } | null;
  deliveredAt: string | null; isDelayed: boolean; delayReason: string | null; scheduledFor: string | null;
  items: OrderItemRow[]; steps: { step: ShipmentStep; label: string }[]; nextStep: ShipmentStep | null; events: ShipmentEventRow[];
}
interface PaymentRow {
  id: number; provider: string; method: PaymentMethod; amount: number; kind: string; status: string; payerPhone: string | null;
  providerReference: string; providerTransactionId: string | null; failureReason: string | null; paidAt: string | null;
  isManual: boolean; isDuplicate: boolean; refundedAmount: number; createdAt: string;
  events: { id: number; eventId: string; signatureValid: boolean; outcome: string | null; createdAt: string }[];
}
interface OrderDetailData {
  id: number; number: string; createdAt: string; contactName: string; contactPhone: string; contactEmail: string | null;
  city: string | null; zoneName: string | null; district: string | null; landmark: string | null; instructions: string | null;
  deliveryMode: string; deliveryGrouping: string; orderType: string;
  subtotal: number; discountTotal: number; shippingTotal: number; total: number; depositAmount: number;
  amountPaid: number; amountRefunded: number; balanceDue: number; initialDue: number;
  status: OrderStatus; paymentStatus: PaymentStatus; paymentMethod: PaymentMethod;
  couponCode: string | null; preorderTermsAcceptedAt: string | null; preorderTermsText: string | null; cgvAcceptedAt: string | null;
  source: string; notesInternal: string | null; cancelledReason: string | null; trackingToken: string; ipAddress: string | null;
  customer: { id: number; fullName: string; phone: string; email: string | null; isGuest: boolean } | null;
  items: OrderItemRow[];
  shipments: ShipmentRow[];
  payments: PaymentRow[];
  refunds: { id: number; amount: number; reason: string; status: string; isManual: boolean; manualReference: string | null; createdAt: string; adminUser: { fullName: string } | null }[];
  cashCollections: { id: number; amount: number; method: string; collectedAt: string; reference: string | null; collectedBy: { fullName: string } | null }[];
  history: { id: number; action: string; createdAt: string; ip: string | null; adminUser: { fullName: string } | null }[];
  conversations: { id: number; status: string; visitorName: string; lastMessageAt: string }[];
  notifications: { id: number; channel: string; template: string; status: string; createdAt: string }[];
}

type ActionKind = 'confirm' | 'cancel' | 'note' | 'step' | 'delay' | 'driver' | 'code' | 'cash' | 'refund' | 'manual' | 'resend' | null;

export default function OrderDetail() {
  const { id = '' } = useParams();
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const user = useAuthStore((s) => s.user);
  const { data: order, isLoading, error } = useQuery({ queryKey: ['order', id], queryFn: () => api<OrderDetailData>(`/admin/orders/${id}`) });
  const { data: drivers } = useQuery({ queryKey: ['drivers'], queryFn: () => api<DriverOption[]>('/admin/users/drivers') });
  const [action, setAction] = useState<ActionKind>(null);
  const [form, setForm] = useState<Record<string, string | number | boolean>>({});
  const [formError, setFormError] = useState<string | null>(null);

  const refresh = () => qc.invalidateQueries({ queryKey: ['order', id] });
  const mutate = useMutation({
    mutationFn: ({ path, body }: { path: string; body?: unknown }) => api(path, { method: 'POST', body }),
    onSuccess: (data: unknown) => {
      const msg = (data as { message?: string })?.message;
      notify(msg ?? 'Action enregistrée');
      setAction(null); setForm({}); setFormError(null);
      refresh();
    },
    onError: (e) => setFormError(errorMessage(e)),
  });
  const act = (path: string, body?: unknown) => mutate.mutate({ path, body });
  const openAction = (kind: Exclude<ActionKind, null>) => { setAction(kind); setForm({}); setFormError(null); };

  if (isLoading) return <PageLoader />;
  if (error || !order) return <Alert tone="error">{error ? errorMessage(error) : 'Commande introuvable'}</Alert>;

  const canStatus = hasPermission(user, Permission.ORDERS_STATUS);
  const canWrite = hasPermission(user, Permission.ORDERS_WRITE);
  const canShip = hasPermission(user, Permission.SHIPMENTS_WRITE);
  const canDelivery = hasPermission(user, Permission.DELIVERY_WRITE);
  const canRefund = hasPermission(user, Permission.ORDERS_REFUND);
  const canManualPay = hasPermission(user, Permission.PAYMENTS_MANUAL);
  const active = ![OrderStatus.CANCELLED, OrderStatus.REFUNDED, OrderStatus.DELIVERED].includes(order.status);
  const printUrl = `${(import.meta.env.VITE_API_URL as string) ?? ''}/api/v1/admin/orders/${order.id}/print`;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/commandes" className="btn-ghost !px-2"><ArrowLeft className="h-4 w-4" /></Link>
        <h1 className="font-mono text-xl font-bold">{order.number}</h1>
        <StatusBadge value={order.status} /> <StatusBadge value={order.paymentStatus} />
        <Badge tone="muted">{PAYMENT_METHOD_LABELS[order.paymentMethod]}</Badge>
        <div className="ml-auto flex flex-wrap gap-2">
          <button className="btn-secondary" onClick={() => window.open(`${printUrl}?kind=order`, '_blank')}><Printer className="h-4 w-4" /> Bon de commande</button>
          <button className="btn-secondary" onClick={() => window.open(`${printUrl}?kind=delivery`, '_blank')}><Printer className="h-4 w-4" /> Bon de livraison</button>
          {canStatus && order.status === OrderStatus.PENDING_PAYMENT && <button className="btn-primary" onClick={() => openAction('confirm')}><CheckCircle2 className="h-4 w-4" /> Confirmer</button>}
          {canWrite && active && <button className="btn-danger" onClick={() => openAction('cancel')}><XCircle className="h-4 w-4" /> Annuler</button>}
        </div>
      </div>

      {order.cancelledReason && <Alert tone="error">Commande annulée : {order.cancelledReason}</Alert>}
      {order.preorderTermsAcceptedAt && (
        <Alert tone="info">
          Le client a accepté le délai de précommande le {formatDate(order.preorderTermsAcceptedAt, true)}
          {order.preorderTermsText ? ` — « ${order.preorderTermsText} »` : ''}. CGV acceptées le {formatDate(order.cgvAcceptedAt, true)}.
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <div className="card p-4">
            <h2 className="mb-3 font-semibold">Articles ({order.items.length})</h2>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-sm">
                <thead><tr className="border-b border-sand text-left text-xs uppercase text-ink-soft"><th className="py-2">Article</th><th>Type</th><th>Prix</th><th>Qté</th><th className="text-right">Total</th></tr></thead>
                <tbody className="divide-y divide-sand/60">
                  {order.items.map((i) => (
                    <tr key={i.id}>
                      <td className="py-2.5">
                        <div className="flex items-center gap-2">
                          {i.imageUrl && <img src={assetUrl(i.imageUrl)} alt="" className="h-9 w-9 rounded object-cover" />}
                          <div><p className="font-medium">{i.productName}</p><p className="text-xs text-ink-soft">{i.reference}{i.variantName ? ` · ${i.variantName}` : ''}{i.arrivalId ? ` · arrivage #${i.arrivalId}` : ''}{i.receivedQty ? ` · reçu ${i.receivedQty}/${i.quantity}` : ''}</p></div>
                        </div>
                      </td>
                      <td className="td"><Badge tone={i.itemType === ItemType.PREORDER ? 'warning' : 'success'}>{i.itemType === ItemType.PREORDER ? `Sur commande · ${i.leadTimeDays} j` : 'Stock'}</Badge></td>
                      <td className="td">{formatFCFA(i.unitPrice)}</td>
                      <td className="td">{i.quantity}</td>
                      <td className="td text-right font-semibold">{formatFCFA(i.lineTotal)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {order.shipments.map((s) => (
            <div key={s.id} className="card p-4">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-semibold">Expédition {s.type === ItemType.LOCAL ? 'locale' : 'précommande'}{s.isDelayed && <Badge tone="danger"> en retard</Badge>}</h3>
                <div className="flex flex-wrap items-center gap-2 text-xs">
                  {s.estimatedDeliveryDate && <span className="text-ink-soft">Estimée : {formatDate(s.estimatedDeliveryDate)}</span>}
                  <span className="font-mono font-semibold">Code remise : {s.deliveryCode}</span>
                </div>
              </div>
              <div className="mb-3 flex flex-wrap gap-2 text-xs">
                {s.driver ? <Badge tone="gold">Livreur : {s.driver.fullName}{s.driver.phone ? ` · ${formatPhone(s.driver.phone)}` : ''}</Badge> : <Badge>Non affectée</Badge>}
                {s.scheduledFor && <Badge tone="muted">Prévue : {formatDate(s.scheduledFor, true)}</Badge>}
                {s.deliveredAt && <Badge tone="success">Livrée le {formatDate(s.deliveredAt, true)}</Badge>}
              </div>
              <ol className="mb-3 space-y-1.5">
                {s.steps.map(({ step, label }) => {
                  const ev = s.events.find((e) => e.step === step);
                  return (
                    <li key={step} className="flex items-start gap-2 text-sm">
                      <span className={`mt-1 h-2.5 w-2.5 shrink-0 rounded-full ${ev ? 'bg-success' : 'bg-sand'}`} />
                      <div className="min-w-0 flex-1">
                        <span className={ev ? 'font-medium' : 'text-ink-soft'}>{label}</span>
                        {ev && <span className="ml-2 text-xs text-ink-soft">{formatDate(ev.occurredAt, true)}{ev.adminUser ? ` · ${ev.adminUser.fullName}` : ''}</span>}
                        {ev?.publicComment && <p className="text-xs italic text-ink-soft">{ev.publicComment}</p>}
                        {ev?.internalNote && <p className="text-xs text-warning">Interne : {ev.internalNote}</p>}
                        {ev?.proofUrl && <a href={assetUrl(ev.proofUrl)} target="_blank" rel="noreferrer" className="text-xs text-gold-dark underline">Preuve photo</a>}
                      </div>
                    </li>
                  );
                })}
              </ol>
              <div className="flex flex-wrap gap-2">
                {canShip && s.nextStep && <button className="btn-primary !min-h-[34px] text-xs" onClick={() => { openAction('step'); setForm({ shipmentId: s.id, step: s.nextStep! }); }}>Étape : {STEP_LABELS[s.nextStep]}</button>}
                {canShip && <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => { openAction('delay'); setForm({ shipmentId: s.id }); }}><CalendarClock className="h-3.5 w-3.5" /> Signaler un retard</button>}
                {canDelivery && <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => { openAction('driver'); setForm({ shipmentId: s.id, driverId: s.driver?.id ?? '' }); }}><Truck className="h-3.5 w-3.5" /> Livreur</button>}
                {canDelivery && <button className="btn-ghost !min-h-[34px] text-xs" onClick={() => act(`/admin/shipments/${s.id}/regenerate-code`)}><KeyRound className="h-3.5 w-3.5" /> Régénérer le code</button>}
              </div>
            </div>
          ))}

          <div className="card p-4">
            <h2 className="mb-3 font-semibold">Paiements et encaissements</h2>
            {order.payments.length ? (
              <div className="space-y-3">
                {order.payments.map((p) => (
                  <div key={p.id} className={`rounded-lg border p-3 text-sm ${p.isDuplicate ? 'border-danger/50 bg-danger/5' : 'border-sand'}`}>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="font-semibold">{formatFCFA(p.amount)} · {p.kind} · {p.provider}{p.isManual ? ' (manuel)' : ''}{p.isDuplicate && <Badge tone="danger"> doublon</Badge>}</p>
                      <Badge tone={p.status === 'SUCCEEDED' ? 'success' : p.status === 'PENDING' ? 'warning' : p.status === 'REFUNDED' || p.status === 'PARTIALLY_REFUNDED' ? 'rose' : 'danger'}>{p.status}</Badge>
                    </div>
                    <p className="mt-1 text-xs text-ink-soft">
                      Réf. {p.providerReference}{p.providerTransactionId ? ` · Tx ${p.providerTransactionId}` : ''}{p.payerPhone ? ` · ${formatPhone(p.payerPhone)}` : ''} · {formatDate(p.createdAt, true)}
                      {p.paidAt ? ` · payé le ${formatDate(p.paidAt, true)}` : ''}{p.refundedAmount ? ` · remboursé ${formatFCFA(p.refundedAmount)}` : ''}
                    </p>
                    {p.failureReason && <p className="mt-1 text-xs text-danger">{p.failureReason}</p>}
                    {!!p.events.length && (
                      <details className="mt-1 text-xs"><summary className="cursor-pointer text-ink-soft">{p.events.length} événement(s) webhook</summary>
                        <ul className="mt-1 space-y-0.5 pl-4 text-ink-soft">
                          {p.events.map((e) => <li key={e.id}>{e.eventId} · {e.outcome ?? '—'}{e.signatureValid ? '' : ' · signature invalide'} · {formatDate(e.createdAt, true)}</li>)}
                        </ul>
                      </details>
                    )}
                  </div>
                ))}
              </div>
            ) : <p className="text-sm text-ink-soft">Aucun paiement enregistré.</p>}
            {!!order.cashCollections.length && (
              <ul className="mt-3 space-y-1 text-sm">
                {order.cashCollections.map((c) => <li key={c.id} className="rounded-lg bg-success/10 px-3 py-2">{formatFCFA(c.amount)} encaissés ({c.method}){c.collectedBy ? ` par ${c.collectedBy.fullName}` : ''} le {formatDate(c.collectedAt, true)}{c.reference ? ` · réf. ${c.reference}` : ''}</li>)}
              </ul>
            )}
            {!!order.refunds.length && (
              <ul className="mt-3 space-y-1 text-sm">
                {order.refunds.map((r) => <li key={r.id} className="rounded-lg bg-rose/15 px-3 py-2">Remboursement {formatFCFA(r.amount)} ({r.status}){r.isManual ? ` manuel réf. ${r.manualReference ?? '—'}` : ''} · {r.reason}{r.adminUser ? ` · ${r.adminUser.fullName}` : ''}</li>)}
              </ul>
            )}
            <div className="mt-3 flex flex-wrap gap-2">
              {canWrite && active && order.balanceDue > 0 && <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => openAction('cash')}><Banknote className="h-3.5 w-3.5" /> Encaissement</button>}
              {canRefund && order.amountPaid > 0 && <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => openAction('refund')}><Undo2 className="h-3.5 w-3.5" /> Rembourser</button>}
              {canManualPay && order.balanceDue > 0 && <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => openAction('manual')}><ShieldCheck className="h-3.5 w-3.5" /> Paiement manuel</button>}
              {canStatus && <button className="btn-ghost !min-h-[34px] text-xs" onClick={() => openAction('resend')}><BellRing className="h-3.5 w-3.5" /> Renvoyer une notification</button>}
            </div>
          </div>
        </div>

        <div className="space-y-4">
          <div className="card p-4 text-sm">
            <h2 className="mb-2 font-semibold">Client</h2>
            <p className="font-medium">{order.contactName}{order.customer && <span className="ml-1 text-xs text-ink-soft">(compte #{order.customer.id}{order.customer.isGuest ? ', invité' : ''})</span>}</p>
            <p>{formatPhone(order.contactPhone)}</p>
            {order.contactEmail && <p className="text-ink-soft">{order.contactEmail}</p>}
            {order.customer && <Link to={`/admin/clients/${order.customer.id}`} className="mt-1 inline-block text-xs text-gold-dark hover:underline">Voir la fiche client</Link>}
          </div>
          <div className="card p-4 text-sm">
            <h2 className="mb-2 font-semibold">Livraison</h2>
            <p className="capitalize">{order.deliveryMode === 'PICKUP' ? 'Retrait en boutique' : `À domicile — ${[order.district, order.zoneName, order.city].filter(Boolean).join(', ') || '—'}`}</p>
            {order.landmark && <p className="mt-1 text-xs text-ink-soft">Repère : {order.landmark}</p>}
            {order.instructions && <p className="mt-1 text-xs text-ink-soft">Instructions : {order.instructions}</p>}
            {order.orderType === 'MIXED' && <p className="mt-1 text-xs text-ink-soft">Groupement : {order.deliveryGrouping === 'TOGETHER' ? 'tout ensemble' : 'deux livraisons'}</p>}
          </div>
          <div className="card p-4 text-sm">
            <h2 className="mb-2 font-semibold">Montants</h2>
            <dl className="space-y-1">
              <div className="flex justify-between"><dt>Sous-total</dt><dd><Money value={order.subtotal} /></dd></div>
              {order.discountTotal > 0 && <div className="flex justify-between text-success"><dt>Remise {order.couponCode ? `(${order.couponCode})` : ''}</dt><dd>-{formatFCFA(order.discountTotal)}</dd></div>}
              <div className="flex justify-between"><dt>Livraison</dt><dd><Money value={order.shippingTotal} /></dd></div>
              <div className="flex justify-between border-t border-sand pt-1 font-bold"><dt>Total</dt><dd><Money value={order.total} /></dd></div>
              <div className="flex justify-between text-success"><dt>Payé</dt><dd>{formatFCFA(order.amountPaid)}</dd></div>
              {order.amountRefunded > 0 && <div className="flex justify-between text-rose-dark"><dt>Remboursé</dt><dd>{formatFCFA(order.amountRefunded)}</dd></div>}
              <div className="flex justify-between font-semibold text-warning"><dt>Reste dû</dt><dd>{formatFCFA(order.balanceDue)}</dd></div>
              {order.depositAmount > 0 && <div className="flex justify-between text-xs text-ink-soft"><dt>Acompte prévu</dt><dd>{formatFCFA(order.depositAmount)}</dd></div>}
            </dl>
          </div>
          <div className="card p-4">
            <h2 className="mb-2 font-semibold">Notes internes</h2>
            <pre className="max-h-40 overflow-y-auto whitespace-pre-wrap text-xs text-ink-soft">{order.notesInternal ?? '—'}</pre>
            {canStatus && <button className="btn-secondary mt-2 !min-h-[34px] w-full text-xs" onClick={() => openAction('note')}><MessageSquare className="h-3.5 w-3.5" /> Ajouter une note</button>}
          </div>
          {!!order.conversations.length && (
            <div className="card p-4 text-sm">
              <h2 className="mb-2 font-semibold">Conversations</h2>
              {order.conversations.map((c) => <Link key={c.id} to={`/admin/messagerie/${c.id}`} className="block text-xs text-gold-dark hover:underline">#{c.id} · {c.status} · {formatDate(c.lastMessageAt, true)}</Link>)}
            </div>
          )}
          <div className="card p-4">
            <h2 className="mb-2 font-semibold">Historique</h2>
            <ul className="max-h-64 space-y-1 overflow-y-auto text-xs text-ink-soft">
              {order.history.map((h) => <li key={h.id}>{formatDate(h.createdAt, true)} · <strong className="text-ink">{h.action}</strong> · {h.adminUser?.fullName ?? 'Système'}</li>)}
            </ul>
          </div>
        </div>
      </div>

      {/* Actions */}
      <Modal open={action === 'confirm'} onClose={() => setAction(null)} title="Confirmer la commande">
        <p className="mb-4 text-sm text-ink-soft">Le client sera notifié et les expéditions seront créées.</p>
        <button className="btn-primary w-full" disabled={mutate.isPending} onClick={() => act(`/admin/orders/${order.id}/confirm`)}>Confirmer</button>
      </Modal>
      <Modal open={action === 'cancel'} onClose={() => setAction(null)} title="Annuler la commande">
        <Field label="Motif (obligatoire)"><textarea className="input" rows={3} value={String(form.reason ?? '')} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-danger mt-3 w-full" disabled={mutate.isPending} onClick={() => act(`/admin/orders/${order.id}/cancel`, { reason: String(form.reason ?? '') })}>Annuler et libérer le stock</button>
      </Modal>
      <Modal open={action === 'note'} onClose={() => setAction(null)} title="Note interne">
        <Field label="Note"><textarea className="input" rows={3} value={String(form.note ?? '')} onChange={(e) => setForm({ ...form, note: e.target.value })} /></Field>
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={mutate.isPending} onClick={() => act(`/admin/orders/${order.id}/notes`, { note: String(form.note ?? '') })}>Enregistrer</button>
      </Modal>
      <Modal open={action === 'step'} onClose={() => setAction(null)} title="Enregistrer une étape">
        <Field label="Étape"><select className="input" value={String(form.step ?? '')} onChange={(e) => setForm({ ...form, step: e.target.value })}>
          <option value="">—</option>
          {(order.shipments.find((s) => s.id === Number(form.shipmentId))?.steps ?? []).map((s) => <option key={s.step} value={s.step}>{s.label}</option>)}
          {Object.values(ShipmentStep).filter((s) => ['DELAYED', 'CANCELLED', 'RETURNED'].includes(s)).map((s) => <option key={s} value={s}>{STEP_LABELS[s]} (transverse)</option>)}
        </select></Field>
        <Field label="Commentaire public (visible client)"><input className="input" value={String(form.publicComment ?? '')} onChange={(e) => setForm({ ...form, publicComment: e.target.value })} /></Field>
        <Field label="Note interne"><input className="input" value={String(form.internalNote ?? '')} onChange={(e) => setForm({ ...form, internalNote: e.target.value })} /></Field>
        {user?.roles.includes('SUPER_ADMIN') && (
          <label className="mt-1 flex items-start gap-2 text-xs"><input type="checkbox" className="mt-0.5 h-4 w-4 accent-gold" checked={!!form.force} onChange={(e) => setForm({ ...form, force: e.target.checked })} /> Forcer le saut d'étape (justification obligatoire)</label>
        )}
        {!!form.force && <Field label="Justification"><input className="input" value={String(form.justification ?? '')} onChange={(e) => setForm({ ...form, justification: e.target.value })} /></Field>}
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={mutate.isPending || !form.step} onClick={() => act(`/admin/shipments/${form.shipmentId}/steps`, { step: form.step, publicComment: form.publicComment || undefined, internalNote: form.internalNote || undefined, force: form.force || undefined, justification: form.justification || undefined })}>Enregistrer</button>
      </Modal>
      <Modal open={action === 'delay'} onClose={() => setAction(null)} title="Signaler un retard">
        <Field label="Nouvelle date estimée"><input className="input" type="date" value={String(form.newEstimatedDate ?? '')} onChange={(e) => setForm({ ...form, newEstimatedDate: e.target.value })} /></Field>
        <Field label="Motif (visible client)"><input className="input" value={String(form.reason ?? '')} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={mutate.isPending} onClick={() => act(`/admin/shipments/${form.shipmentId}/delay`, { newEstimatedDate: form.newEstimatedDate, reason: form.reason })}>Signaler</button>
      </Modal>
      <Modal open={action === 'driver'} onClose={() => setAction(null)} title="Affecter un livreur">
        <Field label="Livreur"><select className="input" value={String(form.driverId ?? '')} onChange={(e) => setForm({ ...form, driverId: e.target.value })}>
          <option value="">— Retirer l'affectation</option>
          {drivers?.map((d) => <option key={d.id} value={d.id}>{d.fullName}{d.phone ? ` · ${formatPhone(d.phone)}` : ''}</option>)}
        </select></Field>
        <Field label="Date planifiée"><input className="input" type="datetime-local" value={String(form.scheduledFor ?? '')} onChange={(e) => setForm({ ...form, scheduledFor: e.target.value })} /></Field>
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={mutate.isPending} onClick={() => act(`/admin/shipments/${form.shipmentId}/driver`, { driverId: form.driverId ? Number(form.driverId) : null, scheduledFor: form.scheduledFor || undefined })}>Affecter</button>
      </Modal>
      <Modal open={action === 'cash'} onClose={() => setAction(null)} title="Encaissement à la livraison">
        <Field label={`Montant (reste dû : ${formatFCFA(order.balanceDue)})`}><input className="input" inputMode="numeric" value={String(form.amount ?? order.balanceDue)} onChange={(e) => setForm({ ...form, amount: e.target.value.replace(/\D/g, '') })} /></Field>
        <Field label="Moyen"><select className="input" value={String(form.method ?? 'CASH')} onChange={(e) => setForm({ ...form, method: e.target.value })}>
          <option value="CASH">Espèces</option><option value="AIRTEL_MONEY">Airtel Money</option><option value="MOOV_MONEY">Moov Money</option>
        </select></Field>
        <Field label="Référence (facultatif)"><input className="input" value={String(form.reference ?? '')} onChange={(e) => setForm({ ...form, reference: e.target.value })} /></Field>
        <Field label="Expédition"><select className="input" value={String(form.shipmentId ?? '')} onChange={(e) => setForm({ ...form, shipmentId: e.target.value })}>
          <option value="">—</option>{order.shipments.map((s) => <option key={s.id} value={s.id}>#{s.id} ({s.type})</option>)}
        </select></Field>
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={mutate.isPending} onClick={() => act(`/admin/orders/${order.id}/cash`, { amount: Number(form.amount ?? order.balanceDue), method: form.method ?? 'CASH', reference: form.reference || undefined, shipmentId: form.shipmentId ? Number(form.shipmentId) : undefined })}>Enregistrer l'encaissement</button>
      </Modal>
      <Modal open={action === 'refund'} onClose={() => setAction(null)} title="Remboursement">
        <Field label={`Montant (payé : ${formatFCFA(order.amountPaid)})`}><input className="input" inputMode="numeric" value={String(form.amount ?? '')} onChange={(e) => setForm({ ...form, amount: e.target.value.replace(/\D/g, '') })} /></Field>
        <Field label="Motif"><input className="input" value={String(form.reason ?? '')} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
        <Field label="Paiement concerné"><select className="input" value={String(form.paymentId ?? '')} onChange={(e) => setForm({ ...form, paymentId: e.target.value })}>
          <option value="">—</option>{order.payments.filter((p) => p.status === 'SUCCEEDED').map((p) => <option key={p.id} value={p.id}>{formatFCFA(p.amount)} · {p.providerReference}</option>)}
        </select></Field>
        <label className="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!form.manual} onChange={(e) => setForm({ ...form, manual: e.target.checked })} /> Remboursement manuel (hors opérateur)</label>
        {!!form.manual && <Field label="Référence manuelle"><input className="input" value={String(form.manualReference ?? '')} onChange={(e) => setForm({ ...form, manualReference: e.target.value })} /></Field>}
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-danger mt-3 w-full" disabled={mutate.isPending || !form.amount || !form.reason} onClick={() => act(`/admin/orders/${order.id}/refund`, { amount: Number(form.amount), reason: form.reason, paymentId: form.paymentId ? Number(form.paymentId) : undefined, manual: form.manual || undefined, manualReference: form.manualReference || undefined })}>Rembourser</button>
      </Modal>
      <Modal open={action === 'manual'} onClose={() => setAction(null)} title="Marquer un paiement comme reçu (super-admin)">
        <Field label={`Montant (reste dû : ${formatFCFA(order.balanceDue)})`}><input className="input" inputMode="numeric" value={String(form.amount ?? order.balanceDue)} onChange={(e) => setForm({ ...form, amount: e.target.value.replace(/\D/g, '') })} /></Field>
        <Field label="Moyen"><select className="input" value={String(form.method ?? 'AIRTEL_MONEY')} onChange={(e) => setForm({ ...form, method: e.target.value })}>
          <option value="AIRTEL_MONEY">Airtel Money</option><option value="MOOV_MONEY">Moov Money</option><option value="CASH_ON_DELIVERY">Espèces</option>
        </select></Field>
        <Field label="Référence de transaction (obligatoire)"><input className="input" value={String(form.reference ?? '')} onChange={(e) => setForm({ ...form, reference: e.target.value })} /></Field>
        <Field label="Note"><input className="input" value={String(form.note ?? '')} onChange={(e) => setForm({ ...form, note: e.target.value })} /></Field>
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={mutate.isPending || !form.reference} onClick={() => act(`/admin/orders/${order.id}/manual-payment`, { amount: Number(form.amount ?? order.balanceDue), method: form.method ?? 'AIRTEL_MONEY', reference: form.reference, note: form.note || undefined })}>Enregistrer</button>
      </Modal>
      <Modal open={action === 'resend'} onClose={() => setAction(null)} title="Renvoyer une notification">
        <Field label="Gabarit"><select className="input" value={String(form.template ?? '')} onChange={(e) => setForm({ ...form, template: e.target.value })}>
          <option value="">—</option>
          {Object.values(NotificationTemplateCode).map((t) => <option key={t} value={t}>{t}</option>)}
        </select></Field>
        {formError && <Alert tone="error">{formError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={mutate.isPending || !form.template} onClick={() => act(`/admin/orders/${order.id}/resend-notification`, { template: form.template })}>Renvoyer</button>
      </Modal>
    </div>
  );
}
