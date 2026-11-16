import { ItemType, formatFCFA } from '@/shared';
import { Minus, Plus, ShoppingBag, Tag, Trash2, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Alert, EmptyState, Price, Seo, Spinner } from '@/components/ui';
import { api, assetUrl, errorMessage } from '@/lib/api';
import { applyValidationToCart, useCartValidation } from '@/lib/hooks';
import { useUiStore } from '@/store/ui';
import { CartItem, useCartStore } from '@/store/cart';

function CartLine({ item }: { item: CartItem }) {
  const setQuantity = useCartStore((s) => s.setQuantity);
  const remove = useCartStore((s) => s.remove);
  return (
    <li className="flex gap-3 py-4">
      <Link to={`/produit/${item.slug}`} className="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-sand">
        {item.imageUrl ? <img src={assetUrl(item.imageUrl)} alt={item.name} className="h-full w-full object-cover" loading="lazy" /> : null}
      </Link>
      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex items-start justify-between gap-2">
          <Link to={`/produit/${item.slug}`} className="line-clamp-2 text-sm font-medium hover:text-gold-dark">{item.name}</Link>
          <button onClick={() => remove(item.variantId)} className="p-1 text-ink-soft hover:text-danger" aria-label={`Retirer ${item.name}`}>
            <Trash2 className="h-4 w-4" />
          </button>
        </div>
        {item.variantName && <p className="mt-0.5 text-xs text-ink-soft">{item.variantName}</p>}
        <div className="mt-auto flex items-center justify-between pt-1">
          <div className="flex items-center rounded-lg border border-sand bg-white">
            <button className="flex h-9 w-9 items-center justify-center disabled:opacity-40" disabled={item.quantity <= 1} onClick={() => setQuantity(item.variantId, item.quantity - 1)} aria-label="Diminuer"><Minus className="h-3.5 w-3.5" /></button>
            <span className="w-8 text-center text-sm font-semibold">{item.quantity}</span>
            <button className="flex h-9 w-9 items-center justify-center disabled:opacity-40" disabled={item.maxQuantity !== null && item.quantity >= item.maxQuantity} onClick={() => setQuantity(item.variantId, item.quantity + 1)} aria-label="Augmenter"><Plus className="h-3.5 w-3.5" /></button>
          </div>
          <Price price={item.unitPrice * item.quantity} />
        </div>
      </div>
    </li>
  );
}

export default function CartPage() {
  const { items, couponCode, setCoupon } = useCartStore();
  const notify = useUiStore((s) => s.notify);
  const navigate = useNavigate();
  const { data, isLoading, isFetching } = useCartValidation();
  const [couponInput, setCouponInput] = useState(couponCode);
  const [couponError, setCouponError] = useState<string | null>(null);

  useEffect(() => {
    if (data) applyValidationToCart(data);
    if (data?.couponError) setCouponError(data.couponError);
    else setCouponError(null);
  }, [data]);

  const localItems = items.filter((i) => i.itemType === ItemType.LOCAL);
  const preorderItems = items.filter((i) => i.itemType === ItemType.PREORDER);

  if (!items.length) {
    return (
      <div className="container-page py-10">
        <Seo title="Panier" noindex />
        <EmptyState
          icon={<ShoppingBag className="h-10 w-10" />}
          title="Votre panier est vide"
          text="Découvrez nos fleurs éternelles, compositions et objets de décoration pour embellir votre intérieur."
          action={<Link to="/boutique" className="btn-primary">Découvrir la boutique</Link>}
        />
      </div>
    );
  }

  const applyCoupon = async () => {
    const code = couponInput.trim();
    setCoupon(code);
    if (code) {
      try {
        const res = await api<{ couponError: string | null }>('/cart/validate', {
          method: 'POST',
          body: { items: items.map((i) => ({ variantId: i.variantId, quantity: i.quantity })), couponCode: code },
        });
        if (res.couponError) setCouponError(res.couponError);
        else notify('Code promo appliqué');
      } catch (e) {
        setCouponError(errorMessage(e));
      }
    } else {
      setCouponError(null);
    }
  };

  return (
    <div className="container-page py-6">
      <Seo title="Panier" noindex />
      <h1 className="section-title mb-6 !text-3xl">Votre panier</h1>
      {data?.issues.length ? (
        <div className="mb-4 space-y-2">
          {data.issues.map((i, k) => <Alert key={k} tone="warning">{i}</Alert>)}
        </div>
      ) : null}
      <div className="grid gap-8 lg:grid-cols-[1fr_360px]">
        <div className="space-y-6">
          {!!localItems.length && (
            <section className="card p-4 sm:p-5">
              <h2 className="mb-1 flex items-center gap-2 font-serif text-xl">
                <Truck className="h-5 w-5 text-success" /> Disponible à Libreville
              </h2>
              <p className="mb-2 text-xs text-ink-soft">Livraison sous 24–48 h</p>
              <ul className="divide-y divide-sand">{localItems.map((i) => <CartLine key={i.variantId} item={i} />)}</ul>
            </section>
          )}
          {!!preorderItems.length && (
            <section className="card border-warning/40 p-4 sm:p-5">
              <h2 className="mb-1 flex items-center gap-2 font-serif text-xl">
                <Truck className="h-5 w-5 text-warning" /> Sur commande
              </h2>
              <p className="mb-2 text-xs text-ink-soft">Délai estimé : {Math.max(...preorderItems.map((i) => i.leadTimeDays))} jours · acompte à la commande</p>
              <ul className="divide-y divide-sand">{preorderItems.map((i) => <CartLine key={i.variantId} item={i} />)}</ul>
            </section>
          )}
          <section className="card p-4 sm:p-5">
            <label htmlFor="coupon" className="label"><Tag className="mr-1 inline h-4 w-4" /> Code promo</label>
            <div className="flex gap-2">
              <input id="coupon" className="input uppercase" value={couponInput} onChange={(e) => setCouponInput(e.target.value)} placeholder="VOTRE CODE" />
              <button className="btn-secondary shrink-0" onClick={applyCoupon} disabled={isFetching}>Appliquer</button>
            </div>
            {couponError && <p className="field-error" role="alert">{couponError}</p>}
            {data?.coupon && !couponError && <p className="mt-1 text-xs text-success">Code « {data.coupon.code} » appliqué{data.coupon.description ? ` — ${data.coupon.description}` : ''}</p>}
          </section>
        </div>
        <aside className="card h-fit space-y-3 p-5 lg:sticky lg:top-24" aria-label="Récapitulatif">
          <h2 className="font-serif text-xl">Récapitulatif</h2>
          {isLoading || !data ? (
            <div className="flex items-center gap-2 text-sm text-ink-soft"><Spinner className="h-4 w-4" /> Vérification des prix et stocks…</div>
          ) : (
            <>
              <dl className="space-y-2 text-sm">
                <div className="flex justify-between"><dt>Sous-total</dt><dd>{formatFCFA(data.totals.subtotal)}</dd></div>
                {data.totals.discountTotal > 0 && <div className="flex justify-between text-success"><dt>Remise</dt><dd>-{formatFCFA(data.totals.discountTotal)}</dd></div>}
                <div className="flex justify-between"><dt>Livraison</dt><dd className="text-ink-soft">calculée à l’étape suivante</dd></div>
                <div className="flex justify-between border-t border-sand pt-2 text-base font-semibold"><dt>Total estimé</dt><dd className="text-gold-dark">{formatFCFA(data.totals.subtotal - data.totals.discountTotal)}</dd></div>
                {data.totals.depositAmount > 0 && (
                  <div className="rounded-lg bg-warning/10 p-3 text-xs">
                    <p className="font-semibold">Acompte précommande : {formatFCFA(data.totals.depositAmount)}</p>
                    <p className="mt-0.5 text-ink-soft">Le solde sera payable à la livraison.</p>
                  </div>
                )}
              </dl>
              <button className="btn-primary w-full" onClick={() => navigate('/commande')} disabled={!!data.issues.length}>
                Passer commande
              </button>
              <Link to="/boutique" className="block text-center text-sm text-gold-dark underline">Continuer mes achats</Link>
            </>
          )}
        </aside>
      </div>
    </div>
  );
}
