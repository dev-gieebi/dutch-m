import {
  AVAILABILITY_LABELS,
  DeliveryGrouping,
  DeliveryMode,
  DeliveryZoneDto,
  formatFCFA,
  OrderSource,
  PaymentMethod,
  PAYMENT_METHOD_LABELS,
} from '@/shared';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Alert, Badge, Field, StatusBadge } from '@/admin/components/ui';
import { api, errorMessage } from '@/admin/lib/api';
import { useUiStore } from '@/admin/store/ui';

interface VariantHit { variantId: number; productName: string; reference: string; sku: string; variantName: string; stockAvailable: number; availabilityMode: string }
interface CartLine extends CartLineBase { label: string; sku: string; availabilityMode: string; stockAvailable: number }
interface CartLineBase { variantId: number; quantity: number }
interface ValidateResult {
  lines: { variantId: number; name: string; variantName: string; unitPrice: number; quantity: number; lineTotal: number; availability: string; itemType: string; issues: string[]; depositAmount: number }[];
  totals: { subtotal: number; discountTotal: number; shippingTotal: number; total: number; depositAmount: number; dueNow: number; balanceAfter: number; shipmentsCount: number };
  coupon: { code: string; discount: number; freeShipping: boolean } | null;
  couponError: string | null;
  issues: string[];
  hasPreorder: boolean;
  preorderLeadTimeDays: number;
  estimatedPreorderDate: string | null;
  allowedPaymentMethods: PaymentMethod[];
}

const SOURCES: [OrderSource, string][] = [[OrderSource.ADMIN, 'Back-office'], [OrderSource.PHONE, 'Téléphone'], [OrderSource.WHATSAPP, 'WhatsApp']];

export default function OrderCreate() {
  const navigate = useNavigate();
  const notify = useUiStore((s) => s.notify);
  const [search, setSearch] = useState('');
  const [cart, setCart] = useState<CartLine[]>([]);
  const [contact, setContact] = useState({ fullName: '', phone: '', email: '' });
  const [delivery, setDelivery] = useState({ mode: DeliveryMode.HOME as DeliveryMode, zoneId: 0, city: 'Libreville', district: '', landmark: '', instructions: '', grouping: DeliveryGrouping.SPLIT as DeliveryGrouping });
  const [coupon, setCoupon] = useState('');
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>(PaymentMethod.CASH_ON_DELIVERY);
  const [source, setSource] = useState<OrderSource>(OrderSource.ADMIN);
  const [note, setNote] = useState('');
  const [preorderTerms, setPreorderTerms] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data: hits } = useQuery({
    queryKey: ['variant-search', search],
    queryFn: () => api<{ data: VariantHit[] }>('/admin/stock', { query: { q: search, limit: 8 } }),
    enabled: search.length >= 2,
  });
  const { data: zones } = useQuery({ queryKey: ['zones'], queryFn: () => api<DeliveryZoneDto[]>('/delivery/zones') });

  const payloadItems = useMemo(() => cart.map((l) => ({ variantId: l.variantId, quantity: l.quantity })), [cart]);
  const [preview, setPreview] = useState<ValidateResult | null>(null);

  const validate = useMutation({
    mutationFn: () => api<ValidateResult>('/cart/validate', {
      method: 'POST',
      body: { items: payloadItems, couponCode: coupon || undefined, zoneId: delivery.zoneId || undefined, deliveryMode: delivery.mode, grouping: delivery.grouping, phone: contact.phone || undefined },
    }),
    onSuccess: (r) => setPreview(r),
    onError: (e) => setError(errorMessage(e)),
  });

  const create = useMutation({
    mutationFn: () => api<{ id: number; number: string }>('/admin/orders', {
      method: 'POST',
      body: {
        contact: { fullName: contact.fullName, phone: contact.phone, email: contact.email || undefined },
        delivery: {
          mode: delivery.mode,
          zoneId: delivery.mode === DeliveryMode.HOME ? delivery.zoneId || undefined : undefined,
          city: delivery.city || undefined,
          district: delivery.district || undefined,
          landmark: delivery.landmark || undefined,
          instructions: delivery.instructions || undefined,
          grouping: delivery.grouping,
        },
        items: payloadItems,
        couponCode: coupon || undefined,
        paymentMethod,
        preorderTermsAccepted: preorderTerms,
        cgvAccepted: true,
        source,
        note: note || undefined,
      },
    }),
    onSuccess: (o) => { notify(`Commande ${o.number} créée`); navigate(`/admin/commandes/${o.id}`); },
    onError: (e) => setError(errorMessage(e)),
  });

  const addLine = (v: VariantHit) => {
    setCart((prev) => {
      const ex = prev.find((l) => l.variantId === v.variantId);
      return ex ? prev.map((l) => (l.variantId === v.variantId ? { ...l, quantity: l.quantity + 1 } : l))
        : [...prev, { variantId: v.variantId, quantity: 1, label: `${v.productName}${v.variantName !== 'Standard' ? ` – ${v.variantName}` : ''}`, sku: v.sku, availabilityMode: v.availabilityMode, stockAvailable: v.stockAvailable }];
    });
    setPreview(null);
    setSearch('');
  };
  const setQty = (id: number, q: number) => { setCart((prev) => prev.map((l) => (l.variantId === id ? { ...l, quantity: Math.max(1, q) } : l))); setPreview(null); };
  const hasPreorder = cart.some((l) => l.availabilityMode === 'PREORDER');
  const methods = preview?.allowedPaymentMethods ?? Object.values(PaymentMethod);
  const canSubmit = cart.length > 0 && contact.fullName.length >= 2 && contact.phone.length >= 8 && (!hasPreorder || preorderTerms);

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div className="flex items-center gap-3">
        <Link to="/admin/commandes" className="btn-ghost !px-2"><ArrowLeft className="h-4 w-4" /></Link>
        <h1 className="text-2xl">Commande manuelle</h1>
      </div>
      {error && <Alert tone="error">{error}</Alert>}

      <section className="card space-y-3 p-5">
        <h2 className="font-semibold">Articles</h2>
        <div className="relative">
          <input className="input" placeholder="Rechercher un produit (nom, référence, SKU)…" value={search} onChange={(e) => setSearch(e.target.value)} />
          {search.length >= 2 && !!hits?.data.length && (
            <ul className="absolute z-20 mt-1 w-full overflow-hidden rounded-xl border border-sand bg-white shadow-soft">
              {hits.data.filter((v) => !cart.some((l) => l.variantId === v.variantId)).map((v) => (
                <li key={v.variantId}>
                  <button className="flex w-full items-center justify-between gap-3 px-4 py-2 text-left text-sm hover:bg-ivory" onClick={() => addLine(v)}>
                    <span>{v.productName} <span className="text-ink-soft">— {v.variantName}</span> <span className="font-mono text-[10px] text-ink-soft">{v.sku}</span></span>
                    <span className="flex items-center gap-2"><StatusBadge value={v.availabilityMode} /><span className="text-xs text-ink-soft">dispo {v.stockAvailable}</span></span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
        {!cart.length ? <p className="text-sm text-ink-soft">Panier vide.</p> : (
          <ul className="space-y-2">
            {cart.map((l) => (
              <li key={l.variantId} className="flex items-center gap-3 rounded-lg border border-sand p-2.5 text-sm">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{l.label}</p>
                  <p className="text-xs text-ink-soft">{l.sku} · {AVAILABILITY_LABELS[l.availabilityMode as keyof typeof AVAILABILITY_LABELS] ?? l.availabilityMode} · dispo {l.stockAvailable}</p>
                </div>
                <div className="flex items-center gap-1">
                  <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => setQty(l.variantId, l.quantity - 1)}>−</button>
                  <input className="input !min-h-[34px] w-16 text-center" inputMode="numeric" value={l.quantity} onChange={(e) => setQty(l.variantId, Number(e.target.value.replace(/\D/g, '')) || 1)} />
                  <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => setQty(l.variantId, l.quantity + 1)}>+</button>
                </div>
                <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => { setCart(cart.filter((c) => c.variantId !== l.variantId)); setPreview(null); }} aria-label="Retirer"><Trash2 className="h-4 w-4" /></button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <div className="grid gap-4 sm:grid-cols-2">
        <section className="card space-y-3 p-5">
          <h2 className="font-semibold">Client</h2>
          <Field label="Nom complet"><input className="input" value={contact.fullName} onChange={(e) => setContact({ ...contact, fullName: e.target.value })} /></Field>
          <Field label="Téléphone"><input className="input" placeholder="+241 06 12 34 56" value={contact.phone} onChange={(e) => setContact({ ...contact, phone: e.target.value })} /></Field>
          <Field label="E-mail (optionnel)"><input className="input" type="email" value={contact.email} onChange={(e) => setContact({ ...contact, email: e.target.value })} /></Field>
          <Field label="Origine"><select className="input" value={source} onChange={(e) => setSource(e.target.value as OrderSource)}>{SOURCES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></Field>
        </section>

        <section className="card space-y-3 p-5">
          <h2 className="font-semibold">Livraison</h2>
          <Field label="Mode">
            <select className="input" value={delivery.mode} onChange={(e) => setDelivery({ ...delivery, mode: e.target.value as DeliveryMode })}>
              <option value={DeliveryMode.HOME}>Livraison à domicile</option>
              <option value={DeliveryMode.PICKUP}>Retrait en boutique</option>
            </select>
          </Field>
          {delivery.mode === DeliveryMode.HOME && (
            <>
              <Field label="Zone">
                <select className="input" value={delivery.zoneId} onChange={(e) => setDelivery({ ...delivery, zoneId: Number(e.target.value) })}>
                  <option value={0}>— Choisir —</option>
                  {zones?.map((z) => <option key={z.id} value={z.id}>{z.name} ({z.city}) · {formatFCFA(z.fee)}</option>)}
                </select>
              </Field>
              <Field label="Quartier / repère"><input className="input" value={delivery.district} onChange={(e) => setDelivery({ ...delivery, district: e.target.value })} /></Field>
              <Field label="Instructions"><input className="input" value={delivery.instructions} onChange={(e) => setDelivery({ ...delivery, instructions: e.target.value })} /></Field>
            </>
          )}
          {hasPreorder && delivery.mode === DeliveryMode.HOME && (
            <Field label="Regroupement">
              <select className="input" value={delivery.grouping} onChange={(e) => setDelivery({ ...delivery, grouping: e.target.value as DeliveryGrouping })}>
                <option value={DeliveryGrouping.SPLIT}>Deux envois (stock d'abord)</option>
                <option value={DeliveryGrouping.TOGETHER}>Un seul envoi (tout ensemble)</option>
              </select>
            </Field>
          )}
        </section>
      </div>

      <section className="card space-y-3 p-5">
        <h2 className="font-semibold">Paiement & validation</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Code promo"><input className="input font-mono uppercase" value={coupon} onChange={(e) => { setCoupon(e.target.value.toUpperCase()); setPreview(null); }} /></Field>
          <Field label="Moyen de paiement">
            <select className="input" value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value as PaymentMethod)}>
              {methods.map((m) => <option key={m} value={m}>{PAYMENT_METHOD_LABELS[m]}</option>)}
            </select>
          </Field>
          <Field label="Note interne"><input className="input" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        </div>
        <button className="btn-secondary" disabled={!cart.length || validate.isPending} onClick={() => validate.mutate()}>Vérifier le panier et les totaux</button>

        {preview && (
          <div className="space-y-3 rounded-xl bg-ivory/60 p-4 text-sm">
            {preview.issues.map((i) => <Alert key={i} tone="warning">{i}</Alert>)}
            {preview.couponError && <Alert tone="warning">{preview.couponError}</Alert>}
            <ul className="space-y-1">
              {preview.lines.map((l) => (
                <li key={l.variantId} className="flex justify-between gap-3">
                  <span>{l.name}{l.variantName !== 'Standard' ? ` – ${l.variantName}` : ''} × {l.quantity} <StatusBadge value={l.availability} /></span>
                  <span className="font-semibold">{formatFCFA(l.lineTotal)}</span>
                </li>
              ))}
            </ul>
            <dl className="space-y-1 border-t border-sand pt-2 text-right text-sm">
              <div className="flex justify-between"><dt>Sous-total</dt><dd>{formatFCFA(preview.totals.subtotal)}</dd></div>
              {preview.totals.discountTotal > 0 && <div className="flex justify-between text-success"><dt>Remise{preview.coupon ? ` (${preview.coupon.code})` : ''}</dt><dd>− {formatFCFA(preview.totals.discountTotal)}</dd></div>}
              <div className="flex justify-between"><dt>Livraison</dt><dd>{formatFCFA(preview.totals.shippingTotal)}</dd></div>
              <div className="flex justify-between font-bold"><dt>Total</dt><dd>{formatFCFA(preview.totals.total)}</dd></div>
              {preview.totals.depositAmount > 0 && (
                <>
                  <div className="flex justify-between text-gold-dark"><dt>À régler maintenant</dt><dd className="font-semibold">{formatFCFA(preview.totals.dueNow)}</dd></div>
                  <div className="flex justify-between text-xs text-ink-soft"><dt>Solde à l'arrivée / livraison</dt><dd>{formatFCFA(preview.totals.balanceAfter)}</dd></div>
                </>
              )}
            </dl>
            {preview.hasPreorder && (
              <p className="text-xs text-ink-soft">Contient des articles en précommande (délai ≈ {preview.preorderLeadTimeDays} j, estimation {preview.estimatedPreorderDate ? new Date(preview.estimatedPreorderDate).toLocaleDateString('fr-FR') : '—'}).</p>
            )}
          </div>
        )}

        {hasPreorder && (
          <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-0.5 h-4 w-4 accent-gold" checked={preorderTerms} onChange={(e) => setPreorderTerms(e.target.checked)} />
            <span>Le client a accepté les conditions de précommande (délais d'importation, acompte).</span>
          </label>
        )}

        <button className="btn-primary" disabled={!canSubmit || create.isPending} onClick={() => create.mutate()}>
          <ShoppingCart className="h-4 w-4" /> {create.isPending ? 'Création…' : 'Créer la commande'}
        </button>
        {!canSubmit && cart.length > 0 && <p className="text-xs text-ink-soft">Nom, téléphone{hasPreorder ? ' et acceptation des conditions de précommande' : ''} requis.</p>}
        {!!cart.length && <Badge tone="muted"><Plus className="mr-1 h-3 w-3" />{cart.length} article(s)</Badge>}
      </section>
    </div>
  );
}
