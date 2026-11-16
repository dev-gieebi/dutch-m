import {
  CreateOrderResponse,
  DeliveryGrouping,
  DeliveryMode,
  formatFCFA,
  ItemType,
  normalizeGabonPhone,
  PaymentMethod,
  PAYMENT_METHOD_LABELS,
  PaymentStatusDto,
} from '@/shared';
import { ArrowLeft, ArrowRight, Check, HandCoins, PackageCheck, Smartphone, Store, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Alert, EmptyState, Field, Seo, Spinner } from '@/components/ui';
import { api, assetUrl, errorMessage } from '@/lib/api';
import { useCartValidation, useSettings, useZones } from '@/lib/hooks';
import { useAuthStore } from '@/store/auth';
import { useCartStore } from '@/store/cart';

const STEPS = ['Coordonnées', 'Livraison', 'Récapitulatif', 'Paiement'] as const;

interface Form {
  fullName: string;
  phone: string;
  email: string;
  createAccount: boolean;
  password: string;
  deliveryMode: DeliveryMode;
  zoneId: number | null;
  city: string;
  district: string;
  landmark: string;
  instructions: string;
  grouping: DeliveryGrouping;
  preorderTermsAccepted: boolean;
  cgvAccepted: boolean;
  paymentMethod: PaymentMethod | '';
  payerPhone: string;
}

const initial: Form = {
  fullName: '', phone: '', email: '', createAccount: false, password: '',
  deliveryMode: DeliveryMode.HOME, zoneId: null, city: 'Libreville', district: '', landmark: '', instructions: '',
  grouping: DeliveryGrouping.TOGETHER, preorderTermsAccepted: false, cgvAccepted: false,
  paymentMethod: '', payerPhone: '',
};

export default function CheckoutPage() {
  const navigate = useNavigate();
  const { items, couponCode, clear } = useCartStore();
  const customer = useAuthStore((s) => s.customer);
  const { data: settings } = useSettings();
  const { data: zones } = useZones();
  const [step, setStep] = useState(0);
  const [f, setF] = useState<Form>(() => ({
    ...initial,
    fullName: customer?.fullName ?? '',
    phone: customer?.phone ?? '',
    email: customer?.email ?? '',
    payerPhone: customer?.phone ?? '',
  }));
  const [errors, setErrors] = useState<Partial<Record<keyof Form, string>>>({});
  const [submitting, setSubmitting] = useState(false);
  const [serverError, setServerError] = useState<string | null>(null);

  const validationParams = useMemo(
    () => ({ zoneId: f.deliveryMode === DeliveryMode.HOME ? (f.zoneId ?? undefined) : undefined, deliveryMode: f.deliveryMode, grouping: f.grouping }),
    [f.deliveryMode, f.zoneId, f.grouping],
  );
  const { data: validation, isFetching } = useCartValidation(validationParams);
  const mixed = (validation?.hasLocal && validation?.hasPreorder) ?? false;
  const pickupAvailable = settings?.pickup.enabled ?? false;

  const set = <K extends keyof Form>(key: K, value: Form[K]) => {
    setF((prev) => ({ ...prev, [key]: value }));
    setErrors((e) => ({ ...e, [key]: undefined }));
  };

  const validateStep = (s: number): boolean => {
    const e: Partial<Record<keyof Form, string>> = {};
    if (s === 0) {
      if (f.fullName.trim().length < 2) e.fullName = 'Indiquez votre nom complet';
      if (!normalizeGabonPhone(f.phone)) e.phone = 'Numéro invalide (format attendu : +241…)';
      if (f.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.email)) e.email = 'Adresse e-mail invalide';
      if (f.createAccount && f.password.length < 8) e.password = '8 caractères minimum';
    }
    if (s === 1) {
      if (f.deliveryMode === DeliveryMode.HOME) {
        if (!f.zoneId) e.zoneId = 'Choisissez votre zone de livraison';
        if (f.landmark.trim().length < 3) e.landmark = 'Indiquez un point de repère (obligatoire pour le livreur)';
      }
    }
    if (s === 2) {
      if (validation?.hasPreorder && !f.preorderTermsAccepted) e.preorderTermsAccepted = 'Veuillez confirmer le délai de précommande';
      if (!f.cgvAccepted) e.cgvAccepted = 'Veuillez accepter les conditions générales de vente';
    }
    setErrors(e);
    return !Object.keys(e).length;
  };

  const next = () => {
    if (validateStep(step)) setStep(Math.min(3, step + 1));
  };

  const allowedMethods = validation?.allowedPaymentMethods ?? [PaymentMethod.CASH_ON_DELIVERY];

  const submit = async () => {
    if (!f.paymentMethod) {
      setErrors({ paymentMethod: 'Choisissez un moyen de paiement' });
      return;
    }
    if (f.paymentMethod !== PaymentMethod.CASH_ON_DELIVERY && !normalizeGabonPhone(f.payerPhone)) {
      setErrors({ payerPhone: 'Indiquez le numéro à débiter (+241…)' });
      return;
    }
    setSubmitting(true);
    setServerError(null);
    try {
      const order = await api<CreateOrderResponse>('/orders', {
        method: 'POST',
        body: {
          contact: {
            fullName: f.fullName.trim(),
            phone: normalizeGabonPhone(f.phone),
            email: f.email.trim() || undefined,
            createAccount: f.createAccount || undefined,
            password: f.createAccount ? f.password : undefined,
          },
          delivery: {
            mode: f.deliveryMode,
            zoneId: f.deliveryMode === DeliveryMode.HOME ? f.zoneId : undefined,
            city: f.city || undefined,
            district: f.district || undefined,
            landmark: f.landmark || undefined,
            instructions: f.instructions || undefined,
            grouping: mixed ? f.grouping : undefined,
          },
          items: items.map((i) => ({ variantId: i.variantId, quantity: i.quantity })),
          couponCode: couponCode || undefined,
          paymentMethod: f.paymentMethod,
          preorderTermsAccepted: validation?.hasPreorder ? true : undefined,
          cgvAccepted: true,
        },
      });
      clear();
      if (f.paymentMethod === PaymentMethod.CASH_ON_DELIVERY) {
        navigate(`/commande/confirmation/${order.trackingToken}`);
        return;
      }
      const payment = await api<PaymentStatusDto>('/payments', {
        method: 'POST',
        body: { orderNumber: order.number, trackingToken: order.trackingToken, method: f.paymentMethod, payerPhone: normalizeGabonPhone(f.payerPhone) },
      });
      navigate(`/paiement/${payment.id}?token=${order.trackingToken}`);
    } catch (e) {
      setServerError(errorMessage(e));
      setSubmitting(false);
    }
  };

  if (!items.length) {
    return (
      <div className="container-page py-10">
        <Seo title="Commande" noindex />
        <EmptyState title="Votre panier est vide" text="Ajoutez des articles avant de passer commande." action={<Link to="/boutique" className="btn-primary">Voir la boutique</Link>} />
      </div>
    );
  }

  const t = validation?.totals;

  return (
    <div className="container-page max-w-4xl py-6">
      <Seo title="Finaliser ma commande" noindex />
      <h1 className="section-title mb-6 !text-3xl">Finaliser ma commande</h1>
      <ol className="mb-8 flex items-center gap-1 text-xs" aria-label="Étapes de la commande">
        {STEPS.map((label, i) => (
          <li key={label} className="flex flex-1 flex-col items-center gap-1">
            <span className={`flex h-8 w-8 items-center justify-center rounded-full border-2 text-sm font-semibold ${i < step ? 'border-gold bg-gold text-white' : i === step ? 'border-gold text-gold' : 'border-sand text-ink-soft'}`}>
              {i < step ? <Check className="h-4 w-4" /> : i + 1}
            </span>
            <span className={`text-center ${i === step ? 'font-semibold text-ink' : 'text-ink-soft'}`}>{label}</span>
          </li>
        ))}
      </ol>

      {step === 0 && (
        <section className="card space-y-4 p-5 sm:p-6">
          <h2 className="font-serif text-2xl">Vos coordonnées</h2>
          <Field label="Nom complet *" error={errors.fullName}>
            <input className="input" value={f.fullName} onChange={(e) => set('fullName', e.target.value)} autoComplete="name" placeholder="Ex. Aïcha Nguema" />
          </Field>
          <Field label="Téléphone *" error={errors.phone} hint="Format gabonais : +241 suivi de votre numéro">
            <input className="input" value={f.phone} onChange={(e) => set('phone', e.target.value)} inputMode="tel" autoComplete="tel" placeholder="+241 77 12 34 56" />
          </Field>
          <Field label="E-mail (facultatif)" error={errors.email} hint="Pour recevoir la confirmation et le lien de suivi par e-mail">
            <input className="input" type="email" value={f.email} onChange={(e) => set('email', e.target.value)} autoComplete="email" placeholder="vous@exemple.com" />
          </Field>
          {!customer && (
            <div className="rounded-xl bg-sand/60 p-4">
              <label className="flex items-start gap-3 text-sm">
                <input type="checkbox" className="mt-1 h-4 w-4 accent-gold" checked={f.createAccount} onChange={(e) => set('createAccount', e.target.checked)} />
                <span><strong>Créer mon compte</strong> — retrouvez vos commandes, adresses et favoris.</span>
              </label>
              {f.createAccount && (
                <Field label="Mot de passe *" error={errors.password}>
                  <input className="input mt-2" type="password" value={f.password} onChange={(e) => set('password', e.target.value)} autoComplete="new-password" placeholder="8 caractères minimum" />
                </Field>
              )}
            </div>
          )}
          <button className="btn-primary w-full sm:w-auto" onClick={next}>Continuer <ArrowRight className="h-4 w-4" /></button>
        </section>
      )}

      {step === 1 && (
        <section className="card space-y-4 p-5 sm:p-6">
          <h2 className="font-serif text-2xl">Mode de livraison</h2>
          <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Mode de livraison">
            <button
              onClick={() => set('deliveryMode', DeliveryMode.HOME)}
              className={`flex items-start gap-3 rounded-xl border-2 p-4 text-left ${f.deliveryMode === DeliveryMode.HOME ? 'border-gold bg-gold/5' : 'border-sand'}`}
              role="radio" aria-checked={f.deliveryMode === DeliveryMode.HOME}
            >
              <Truck className="mt-0.5 h-6 w-6 text-gold" />
              <span><strong>Livraison à domicile</strong><br /><span className="text-xs text-ink-soft">Libreville et environs</span></span>
            </button>
            {pickupAvailable && (
              <button
                onClick={() => set('deliveryMode', DeliveryMode.PICKUP)}
                className={`flex items-start gap-3 rounded-xl border-2 p-4 text-left ${f.deliveryMode === DeliveryMode.PICKUP ? 'border-gold bg-gold/5' : 'border-sand'}`}
                role="radio" aria-checked={f.deliveryMode === DeliveryMode.PICKUP}
              >
                <Store className="mt-0.5 h-6 w-6 text-gold" />
                <span><strong>Retrait en boutique</strong><br /><span className="text-xs text-ink-soft">{settings!.pickup.address} · {settings!.pickup.hours}</span></span>
              </button>
            )}
          </div>

          {f.deliveryMode === DeliveryMode.HOME && (
            <>
              <Field label="Zone / quartier *" error={errors.zoneId}>
                <select className="input" value={f.zoneId ?? ''} onChange={(e) => set('zoneId', e.target.value ? Number(e.target.value) : null)}>
                  <option value="">Choisissez votre zone…</option>
                  {zones?.map((z) => (
                    <option key={z.id} value={z.id}>{z.name} — {formatFCFA(z.fee)} · {z.estimatedDelay}</option>
                  ))}
                </select>
              </Field>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Ville">
                  <input className="input" value={f.city} onChange={(e) => set('city', e.target.value)} />
                </Field>
                <Field label="Quartier précis">
                  <input className="input" value={f.district} onChange={(e) => set('district', e.target.value)} placeholder="Ex. Akébé, près de la mairie" />
                </Field>
              </div>
              <Field label="Point de repère *" error={errors.landmark} hint="Indication indispensable pour que le livreur vous trouve (école, pharmacie, portail…)">
                <input className="input" value={f.landmark} onChange={(e) => set('landmark', e.target.value)} placeholder="Ex. Derrière la pharmacie M'bolo, portail bleu" />
              </Field>
              <Field label="Instructions pour le livreur">
                <textarea className="input" rows={2} value={f.instructions} onChange={(e) => set('instructions', e.target.value)} placeholder="Étage, code d'entrée, créneau préféré…" />
              </Field>
            </>
          )}

          {mixed && (
            <fieldset className="rounded-xl bg-warning/10 p-4">
              <legend className="label">Votre panier est mixte (stock + précommande)</legend>
              <div className="space-y-2">
                <label className="flex items-start gap-3 text-sm">
                  <input type="radio" name="grouping" className="mt-1 h-4 w-4 accent-gold" checked={f.grouping === DeliveryGrouping.TOGETHER} onChange={() => set('grouping', DeliveryGrouping.TOGETHER)} />
                  <span><strong>Tout recevoir ensemble</strong> — une seule livraison quand la précommande arrive ({validation?.preorderLeadTimeDays} j).</span>
                </label>
                <label className="flex items-start gap-3 text-sm">
                  <input type="radio" name="grouping" className="mt-1 h-4 w-4 accent-gold" checked={f.grouping === DeliveryGrouping.SPLIT} onChange={() => set('grouping', DeliveryGrouping.SPLIT)} />
                  <span><strong>Recevoir en deux fois</strong> — le stock sous 24–48 h, puis la précommande. <em>Les frais de livraison s'appliquent deux fois.</em></span>
                </label>
              </div>
            </fieldset>
          )}
          <div className="flex flex-col gap-2 sm:flex-row">
            <button className="btn-ghost" onClick={() => setStep(0)}><ArrowLeft className="h-4 w-4" /> Retour</button>
            <button className="btn-primary flex-1 sm:flex-none" onClick={next}>Continuer <ArrowRight className="h-4 w-4" /></button>
          </div>
        </section>
      )}

      {step === 2 && (
        <section className="card space-y-4 p-5 sm:p-6">
          <h2 className="font-serif text-2xl">Récapitulatif</h2>
          <ul className="divide-y divide-sand">
            {(validation?.lines ?? []).map((l) => (
              <li key={l.variantId} className="flex items-center gap-3 py-3 text-sm">
                <span className="h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-sand">
                  {l.imageUrl && <img src={assetUrl(l.imageUrl)} alt="" className="h-full w-full object-cover" loading="lazy" />}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="line-clamp-1 font-medium">{l.name}</span>
                  {l.variantName && l.variantName !== 'Standard' && <span className="text-xs text-ink-soft"> · {l.variantName}</span>}
                  <span className="block text-xs text-ink-soft">{l.itemType === ItemType.PREORDER ? `Sur commande · ${l.leadTimeDays} j` : 'En stock'} × {l.quantity}</span>
                </span>
                <span className="font-medium">{formatFCFA(l.lineTotal)}</span>
              </li>
            ))}
          </ul>
          <dl className="space-y-2 border-t border-sand pt-3 text-sm">
            <div className="flex justify-between"><dt>Sous-total</dt><dd>{formatFCFA(t?.subtotal)}</dd></div>
            {!!t?.discountTotal && <div className="flex justify-between text-success"><dt>Remise{validation?.coupon ? ` (${validation.coupon.code})` : ''}</dt><dd>-{formatFCFA(t.discountTotal)}</dd></div>}
            <div className="flex justify-between"><dt>Livraison{t && t.shipmentsCount > 1 ? ` (${t.shipmentsCount} envois)` : ''}</dt><dd>{t?.freeShipping ? 'Offerte' : formatFCFA(t?.shippingTotal)}</dd></div>
            <div className="flex justify-between border-t border-sand pt-2 text-base font-semibold"><dt>Total</dt><dd className="text-gold-dark">{formatFCFA(t?.total)}</dd></div>
            {!!t?.depositAmount && (
              <div className="rounded-lg bg-warning/10 p-3 text-xs">
                <div className="flex justify-between font-semibold"><span>Acompte à payer maintenant ({validation?.depositPercent} %)</span><span>{formatFCFA(t.dueNow)}</span></div>
                <div className="mt-0.5 flex justify-between text-ink-soft"><span>Solde à la livraison</span><span>{formatFCFA(t.balanceAfter)}</span></div>
              </div>
            )}
          </dl>
          {validation?.hasPreorder && (
            <div>
              <label className="flex items-start gap-3 rounded-xl border border-warning/40 bg-warning/10 p-4 text-sm">
                <input type="checkbox" className="mt-1 h-4 w-4 accent-gold" checked={f.preorderTermsAccepted} onChange={(e) => set('preorderTermsAccepted', e.target.checked)} />
                <span>
                  J'ai compris que certains articles sont <strong>en précommande</strong> avec un délai estimé de{' '}
                  <strong>{validation.preorderLeadTimeDays} jours</strong>, susceptible de varier (transport, douane).
                  Date estimée : <strong>{validation.estimatedPreorderDate}</strong>.
                </span>
              </label>
              {errors.preorderTermsAccepted && <p className="field-error" role="alert">{errors.preorderTermsAccepted}</p>}
            </div>
          )}
          <div>
            <label className="flex items-start gap-3 rounded-xl border border-sand bg-white p-4 text-sm">
              <input type="checkbox" className="mt-1 h-4 w-4 accent-gold" checked={f.cgvAccepted} onChange={(e) => set('cgvAccepted', e.target.checked)} />
              <span>J'accepte les <Link to="/pages/cgv" target="_blank" className="text-gold-dark underline">conditions générales de vente</Link> de DutCh’M.</span>
            </label>
            {errors.cgvAccepted && <p className="field-error" role="alert">{errors.cgvAccepted}</p>}
          </div>
          <div className="flex flex-col gap-2 sm:flex-row">
            <button className="btn-ghost" onClick={() => setStep(1)}><ArrowLeft className="h-4 w-4" /> Retour</button>
            <button className="btn-primary flex-1 sm:flex-none" onClick={next} disabled={isFetching}>Choisir le paiement <ArrowRight className="h-4 w-4" /></button>
          </div>
        </section>
      )}

      {step === 3 && (
        <section className="card space-y-4 p-5 sm:p-6">
          <h2 className="font-serif text-2xl">Paiement</h2>
          <div className="space-y-3" role="radiogroup" aria-label="Moyen de paiement">
            {allowedMethods.includes(PaymentMethod.CASH_ON_DELIVERY) && (
              <button
                onClick={() => set('paymentMethod', PaymentMethod.CASH_ON_DELIVERY)}
                className={`flex w-full items-start gap-3 rounded-xl border-2 p-4 text-left ${f.paymentMethod === PaymentMethod.CASH_ON_DELIVERY ? 'border-gold bg-gold/5' : 'border-sand'}`}
                role="radio" aria-checked={f.paymentMethod === PaymentMethod.CASH_ON_DELIVERY}
              >
                <HandCoins className="mt-0.5 h-6 w-6 text-gold" />
                <span><strong>{PAYMENT_METHOD_LABELS[PaymentMethod.CASH_ON_DELIVERY]}</strong><br />
                  <span className="text-xs text-ink-soft">Espèces ou mobile money à la remise de votre commande.</span></span>
              </button>
            )}
            {allowedMethods.includes(PaymentMethod.AIRTEL_MONEY) && (
              <button
                onClick={() => set('paymentMethod', PaymentMethod.AIRTEL_MONEY)}
                className={`flex w-full items-start gap-3 rounded-xl border-2 p-4 text-left ${f.paymentMethod === PaymentMethod.AIRTEL_MONEY ? 'border-gold bg-gold/5' : 'border-sand'}`}
                role="radio" aria-checked={f.paymentMethod === PaymentMethod.AIRTEL_MONEY}
              >
                <Smartphone className="mt-0.5 h-6 w-6 text-danger" />
                <span><strong>{PAYMENT_METHOD_LABELS[PaymentMethod.AIRTEL_MONEY]}</strong><br />
                  <span className="text-xs text-ink-soft">Validez le paiement sur votre téléphone — {formatFCFA(t?.dueNow)}.</span></span>
              </button>
            )}
            {allowedMethods.includes(PaymentMethod.MOOV_MONEY) && (
              <button
                onClick={() => set('paymentMethod', PaymentMethod.MOOV_MONEY)}
                className={`flex w-full items-start gap-3 rounded-xl border-2 p-4 text-left ${f.paymentMethod === PaymentMethod.MOOV_MONEY ? 'border-gold bg-gold/5' : 'border-sand'}`}
                role="radio" aria-checked={f.paymentMethod === PaymentMethod.MOOV_MONEY}
              >
                <Smartphone className="mt-0.5 h-6 w-6 text-[#0067B1]" />
                <span><strong>{PAYMENT_METHOD_LABELS[PaymentMethod.MOOV_MONEY]}</strong><br />
                  <span className="text-xs text-ink-soft">Validez le paiement sur votre téléphone — {formatFCFA(t?.dueNow)}.</span></span>
              </button>
            )}
          </div>
          {errors.paymentMethod && <p className="field-error" role="alert">{errors.paymentMethod}</p>}

          {f.paymentMethod && f.paymentMethod !== PaymentMethod.CASH_ON_DELIVERY && (
            <Field label={`Numéro ${f.paymentMethod === PaymentMethod.AIRTEL_MONEY ? 'Airtel Money' : 'Moov Money'} à débiter *`} error={errors.payerPhone}>
              <input className="input" inputMode="tel" value={f.payerPhone} onChange={(e) => set('payerPhone', e.target.value)} placeholder="+241 77 12 34 56" />
            </Field>
          )}
          {serverError && <Alert tone="error">{serverError}</Alert>}
          <div className="flex flex-col gap-2 sm:flex-row">
            <button className="btn-ghost" onClick={() => setStep(2)} disabled={submitting}><ArrowLeft className="h-4 w-4" /> Retour</button>
            <button className="btn-primary flex-1" onClick={submit} disabled={submitting}>
              {submitting ? <><Spinner /> Traitement en cours…</> : <><PackageCheck className="h-4 w-4" /> Valider ma commande{t ? ` — ${formatFCFA(f.paymentMethod === PaymentMethod.CASH_ON_DELIVERY ? t.total : t.dueNow)}` : ''}</>}
            </button>
          </div>
          <p className="text-center text-xs text-ink-soft">En validant, votre stock est réservé immédiatement. Les paiements mobiles expirent après 30 minutes.</p>
        </section>
      )}
    </div>
  );
}
