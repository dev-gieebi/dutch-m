import {
  addDays,
  AvailabilityMode,
  buildWhatsAppLink,
  formatDate,
  formatFCFA,
  ImageDto,
  ItemType,
  ProductCardDto,
  ProductDetailDto,
  VariantDto,
} from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { Bell, ChevronLeft, ChevronRight, Heart, MessageCircle, Minus, Plus, ShoppingBag, Truck, X, ZoomIn } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ProductGrid } from '@/components/ProductCard';
import { Alert, AvailabilityBadge, Breadcrumbs, Modal, PageSkeleton, Price, Seo, Spinner } from '@/components/ui';
import { api, assetUrl, errorMessage, SITE_URL } from '@/lib/api';
import { useSettings } from '@/lib/hooks';
import { useAuthStore } from '@/store/auth';
import { useCartStore } from '@/store/cart';
import { useUiStore } from '@/store/ui';
import NotFound from './NotFound';

type ProductDetail = ProductDetailDto & { rating: { average: number | null; count: number } };

function Gallery({ images, name }: { images: ImageDto[]; name: string }) {
  const [index, setIndex] = useState(0);
  const [full, setFull] = useState(false);
  const [zoom, setZoom] = useState<{ x: number; y: number } | null>(null);
  useEffect(() => { setIndex(0); }, [images]);
  const current = images[index];
  if (!current) return <div className="flex aspect-square items-center justify-center rounded-xl bg-sand font-serif text-3xl text-gold">DutCh’M</div>;
  const go = (d: number) => setIndex((index + d + images.length) % images.length);
  return (
    <div>
      <div className="relative overflow-hidden rounded-xl bg-sand">
        <button className="block w-full cursor-zoom-in" onClick={() => setFull(true)} aria-label="Agrandir l'image">
          <img
            src={assetUrl(current.mediumUrl)}
            srcSet={`${assetUrl(current.mediumUrl)} 800w, ${assetUrl(current.url)} 1600w`}
            sizes="(min-width: 768px) 50vw, 100vw"
            alt={current.alt || name}
            className="aspect-square w-full object-cover"
          />
          <ZoomIn className="absolute bottom-3 right-3 h-9 w-9 rounded-full bg-ivory/80 p-2 text-ink" aria-hidden="true" />
        </button>
        {images.length > 1 && (
          <>
            <button onClick={() => go(-1)} className="absolute left-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-ivory/80" aria-label="Image précédente"><ChevronLeft /></button>
            <button onClick={() => go(1)} className="absolute right-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-ivory/80" aria-label="Image suivante"><ChevronRight /></button>
          </>
        )}
      </div>
      {images.length > 1 && (
        <div className="no-scrollbar mt-3 flex gap-2 overflow-x-auto">
          {images.map((img, i) => (
            <button key={img.id} onClick={() => setIndex(i)} className={`h-16 w-16 shrink-0 overflow-hidden rounded-lg border-2 ${i === index ? 'border-gold' : 'border-transparent'}`} aria-label={`Voir l'image ${i + 1}`}>
              <img src={assetUrl(img.thumbUrl)} alt="" loading="lazy" className="h-full w-full object-cover" />
            </button>
          ))}
        </div>
      )}
      {full && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-ink/95 animate-fade-in" role="dialog" aria-label="Image en plein écran">
          <button className="absolute right-3 top-3 z-10 rounded-full bg-ivory/20 p-3 text-white" onClick={() => { setFull(false); setZoom(null); }} aria-label="Fermer"><X /></button>
          <div className="h-full w-full overflow-auto" style={{ touchAction: 'pinch-zoom' }}>
            <img
              src={assetUrl(current.url)}
              alt={current.alt || name}
              onClick={(e) => {
                const r = e.currentTarget.getBoundingClientRect();
                setZoom(zoom ? null : { x: ((e.clientX - r.left) / r.width) * 100, y: ((e.clientY - r.top) / r.height) * 100 });
              }}
              className={`mx-auto h-full max-w-full object-contain transition-transform duration-300 ${zoom ? 'cursor-zoom-out' : 'cursor-zoom-in'}`}
              style={zoom ? { transform: 'scale(2.2)', transformOrigin: `${zoom.x}% ${zoom.y}%` } : undefined}
            />
          </div>
          {images.length > 1 && (
            <>
              <button onClick={() => go(-1)} className="absolute left-3 top-1/2 rounded-full bg-ivory/20 p-3 text-white" aria-label="Précédente"><ChevronLeft /></button>
              <button onClick={() => go(1)} className="absolute right-3 top-1/2 rounded-full bg-ivory/20 p-3 text-white" aria-label="Suivante"><ChevronRight /></button>
            </>
          )}
        </div>
      )}
    </div>
  );
}

function BackInStock({ variant }: { variant: VariantDto }) {
  const [open, setOpen] = useState(false);
  const [contact, setContact] = useState('');
  const [state, setState] = useState<{ loading?: boolean; done?: string; error?: string }>({});
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setState({ loading: true });
    try {
      const body = contact.includes('@') ? { email: contact } : { phone: contact };
      const res = await api<{ message: string }>(`/catalog/variants/${variant.id}/back-in-stock`, { method: 'POST', body });
      setState({ done: res.message });
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };
  return (
    <>
      <button className="btn-secondary w-full" onClick={() => setOpen(true)}><Bell className="h-4 w-4" /> M’alerter du retour en stock</button>
      <Modal open={open} onClose={() => setOpen(false)} title="Alerte retour en stock">
        {state.done ? (
          <Alert tone="success">{state.done}</Alert>
        ) : (
          <form onSubmit={submit} className="space-y-3">
            <p className="text-sm text-ink-soft">Laissez votre e-mail ou votre numéro : nous vous prévenons dès que l’article est de nouveau disponible.</p>
            <input className="input" value={contact} onChange={(e) => setContact(e.target.value)} placeholder="E-mail ou +241…" required aria-label="E-mail ou téléphone" />
            {state.error && <Alert tone="error">{state.error}</Alert>}
            <button className="btn-primary w-full" disabled={state.loading}>{state.loading ? <Spinner /> : 'Me prévenir'}</button>
          </form>
        )}
      </Modal>
    </>
  );
}

export default function ProductPage() {
  const { slug = '' } = useParams();
  const navigate = useNavigate();
  const add = useCartStore((s) => s.add);
  const notify = useUiStore((s) => s.notify);
  const openChat = useUiStore((s) => s.openChat);
  const customer = useAuthStore((s) => s.customer);
  const { data: settings } = useSettings();
  const { data: p, isLoading, isError } = useQuery({ queryKey: ['product', slug], queryFn: () => api<ProductDetail>(`/catalog/products/${slug}`) });
  const { data: related } = useQuery({ queryKey: ['related', slug], queryFn: () => api<ProductCardDto[]>(`/catalog/products/${slug}/related`), enabled: !!p });
  const [selected, setSelected] = useState<Record<string, string>>({});
  const [qty, setQty] = useState(1);
  const [wishLoading, setWishLoading] = useState(false);

  const groups = useMemo(() => {
    const map = new Map<string, { value: string; hexColor: string | null }[]>();
    p?.variants.forEach((v) => v.attributes.forEach((a) => {
      const list = map.get(a.attribute) ?? [];
      if (!list.some((x) => x.value === a.value)) list.push({ value: a.value, hexColor: a.hexColor });
      map.set(a.attribute, list);
    }));
    return [...map.entries()];
  }, [p]);

  useEffect(() => {
    if (!p) return;
    const def = p.variants.find((v) => v.isDefault && v.availability !== AvailabilityMode.UNAVAILABLE) ?? p.variants.find((v) => v.availability !== AvailabilityMode.UNAVAILABLE) ?? p.variants[0];
    setSelected(Object.fromEntries((def?.attributes ?? []).map((a) => [a.attribute, a.value])));
    setQty(1);
  }, [p]);

  const variant = useMemo(
    () => p?.variants.find((v) => groups.every(([attr]) => v.attributes.some((a) => a.attribute === attr && a.value === selected[attr]))) ?? (groups.length ? undefined : p?.variants[0]),
    [p, groups, selected],
  );
  const images = useMemo(() => {
    if (!p) return [];
    if (!variant?.imageIds.length) return p.images.filter((i) => !i.variantId || i.variantId === variant?.id);
    return [...p.images.filter((i) => variant.imageIds.includes(i.id)), ...p.images.filter((i) => !i.variantId)];
  }, [p, variant]);

  if (isLoading) return <PageSkeleton />;
  if (isError || !p) return <NotFound />;

  const availability = variant?.availability ?? AvailabilityMode.UNAVAILABLE;
  const max = variant?.maxQuantity ?? 0;
  const canBuy = !!variant && availability !== AvailabilityMode.UNAVAILABLE && max > 0;
  const estimated = formatDate(addDays(new Date(), p.leadTimeDays));
  const addToCart = () => {
    if (!variant) return;
    add({
      variantId: variant.id, productId: p.id, slug: p.slug, name: p.name, variantName: variant.name === 'Standard' ? null : variant.name,
      imageUrl: images[0]?.thumbUrl ?? null, unitPrice: variant.price, quantity: qty,
      itemType: availability === AvailabilityMode.IN_STOCK ? ItemType.LOCAL : ItemType.PREORDER, leadTimeDays: p.leadTimeDays, maxQuantity: variant.maxQuantity,
    });
  };
  const toggleWishlist = async () => {
    if (!customer) return navigate(`/compte/connexion?redirect=/produit/${p.slug}`);
    setWishLoading(true);
    try {
      await api('/me/wishlist', { method: 'POST', body: { productId: p.id } });
      notify('Ajouté à vos favoris');
    } catch (e) {
      notify(errorMessage(e), 'error');
    } finally {
      setWishLoading(false);
    }
  };
  const waMessage = `Bonjour DutCh’M, j'ai une question sur le produit ${p.name} – réf. ${p.reference}`;
  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: p.name,
    sku: variant?.sku ?? p.reference,
    image: p.images.map((i) => assetUrl(i.url)),
    description: p.seoDescription ?? p.shortDescription ?? undefined,
    brand: { '@type': 'Brand', name: 'DutCh’M' },
    category: p.category?.name,
    ...(p.rating.count ? { aggregateRating: { '@type': 'AggregateRating', ratingValue: p.rating.average, reviewCount: p.rating.count } } : {}),
    offers: {
      '@type': 'Offer',
      priceCurrency: 'XAF',
      price: variant?.price ?? p.price,
      url: `${SITE_URL}/produit/${p.slug}`,
      availability: availability === AvailabilityMode.IN_STOCK ? 'https://schema.org/InStock' : availability === AvailabilityMode.PREORDER ? 'https://schema.org/PreOrder' : 'https://schema.org/OutOfStock',
    },
  };

  return (
    <div className="container-page py-6">
      <Seo title={p.seoTitle ?? p.name} description={p.seoDescription ?? p.shortDescription} image={assetUrl(p.images[0]?.url)} type="product" jsonLd={jsonLd} />
      <Breadcrumbs items={[{ label: 'Boutique', to: '/boutique' }, ...p.breadcrumbs.map((b) => ({ label: b.name, to: `/categorie/${b.slug}` })), { label: p.name }]} />
      <div className="grid gap-8 md:grid-cols-2">
        <Gallery images={images} name={p.name} />
        <div className="space-y-5">
          <div>
            <div className="mb-2 flex flex-wrap gap-2">
              {p.isNew && <span className="badge bg-blush text-rose-dark">Nouveau</span>}
              {variant?.compareAtPrice && <span className="badge bg-rose text-white">Promo</span>}
              <AvailabilityBadge availability={availability} leadTimeDays={p.leadTimeDays} />
            </div>
            <h1 className="text-3xl sm:text-4xl">{p.name}</h1>
            <p className="mt-1 text-xs text-ink-soft">Réf. {variant?.sku ?? p.reference}{p.category && <> · <Link to={`/categorie/${p.category.slug}`} className="underline">{p.category.name}</Link></>}</p>
            <Price price={variant?.price ?? p.price} compareAt={variant?.compareAtPrice ?? p.compareAtPrice} className="mt-3 text-2xl" />
            {p.shortDescription && <p className="mt-3 text-sm leading-relaxed text-ink-soft">{p.shortDescription}</p>}
          </div>

          {groups.map(([attr, values]) => (
            <fieldset key={attr}>
              <legend className="label">{attr} : <span className="font-normal text-ink-soft">{selected[attr]}</span></legend>
              <div className="flex flex-wrap gap-2">
                {values.map((v) => {
                  const exists = p.variants.some((x) => x.attributes.some((a) => a.attribute === attr && a.value === v.value) && groups.every(([g]) => g === attr || x.attributes.some((a) => a.attribute === g && a.value === selected[g])));
                  return (
                    <button
                      key={v.value}
                      onClick={() => { setSelected({ ...selected, [attr]: v.value }); setQty(1); }}
                      className={`badge min-h-[44px] border px-4 text-sm ${selected[attr] === v.value ? 'border-gold bg-gold/10 text-gold-dark' : 'border-sand bg-white'} ${exists ? '' : 'opacity-40'}`}
                      aria-pressed={selected[attr] === v.value}
                    >
                      {v.hexColor && <span className="h-4 w-4 rounded-full border border-sand" style={{ background: v.hexColor }} />}
                      {v.value}
                    </button>
                  );
                })}
              </div>
            </fieldset>
          ))}

          <div className="card space-y-1 p-4 text-sm">
            <p className="flex items-center gap-2 font-medium"><Truck className="h-4 w-4 text-gold" />
              {availability === AvailabilityMode.IN_STOCK
                ? 'Livraison sous 24–48 h à Libreville'
                : availability === AvailabilityMode.PREORDER
                  ? `Sur commande · délai estimé ${p.leadTimeDays} jours (vers le ${estimated})`
                  : 'Actuellement indisponible'}
            </p>
            {availability === AvailabilityMode.PREORDER && (
              <p className="text-xs text-ink-soft">{p.fullPaymentRequired ? 'Paiement total à la commande.' : `Acompte de ${p.depositPercent} % à la commande, solde à la livraison.`} Délai susceptible de varier (transport, douane).</p>
            )}
            {availability === AvailabilityMode.IN_STOCK && variant?.stockAvailable !== null && variant!.stockAvailable! <= 3 && (
              <p className="text-xs font-semibold text-rose-dark">Plus que {variant!.stockAvailable} en stock</p>
            )}
            <Link to="/pages/livraisons" className="text-xs text-gold-dark underline">Frais et zones de livraison</Link>
          </div>

          {canBuy ? (
            <div className="space-y-3">
              <div className="flex items-center gap-3">
                <span className="label !mb-0">Quantité</span>
                <div className="flex items-center rounded-xl border border-sand bg-white">
                  <button className="flex h-11 w-11 items-center justify-center disabled:opacity-40" onClick={() => setQty(Math.max(1, qty - 1))} disabled={qty <= 1} aria-label="Diminuer"><Minus className="h-4 w-4" /></button>
                  <span className="w-10 text-center font-semibold" aria-live="polite">{qty}</span>
                  <button className="flex h-11 w-11 items-center justify-center disabled:opacity-40" onClick={() => setQty(Math.min(max, qty + 1))} disabled={qty >= max} aria-label="Augmenter"><Plus className="h-4 w-4" /></button>
                </div>
              </div>
              <div className="grid gap-2 sm:grid-cols-2">
                <button className="btn-secondary" onClick={() => { addToCart(); notify('Ajouté au panier'); }}><ShoppingBag className="h-4 w-4" /> Ajouter au panier</button>
                <button className="btn-primary" onClick={() => { addToCart(); navigate('/commande'); }}>Commander maintenant</button>
              </div>
            </div>
          ) : (
            variant && <BackInStock variant={variant} />
          )}

          <div className="flex flex-wrap gap-2">
            <button className="btn-ghost" onClick={toggleWishlist} disabled={wishLoading}><Heart className="h-4 w-4" /> Ajouter aux favoris</button>
            <button className="btn-ghost" onClick={() => openChat({ productId: p.id, productName: p.name, productReference: p.reference })}><MessageCircle className="h-4 w-4" /> Poser une question</button>
            {settings && <a className="btn-ghost text-[#128C7E]" href={buildWhatsAppLink(settings.whatsappNumber, waMessage)} target="_blank" rel="noopener noreferrer">WhatsApp</a>}
          </div>

          <dl className="grid grid-cols-2 gap-3 border-t border-sand pt-4 text-sm">
            {p.dimensions && <><dt className="text-ink-soft">Dimensions</dt><dd>{p.dimensions}</dd></>}
            {p.material && <><dt className="text-ink-soft">Matière</dt><dd>{p.material}</dd></>}
            {p.colors.length > 0 && <><dt className="text-ink-soft">Couleurs</dt><dd>{p.colors.join(', ')}</dd></>}
            {p.weight && <><dt className="text-ink-soft">Poids</dt><dd>{p.weight} g</dd></>}
          </dl>
        </div>
      </div>

      <section className="mt-10 grid gap-6 md:grid-cols-2">
        {p.description && (
          <div className="card p-5">
            <h2 className="mb-3 text-2xl">Description</h2>
            <div className="prose-amoria text-sm" dangerouslySetInnerHTML={{ __html: p.description }} />
          </div>
        )}
        {p.careInstructions && (
          <div className="card p-5">
            <h2 className="mb-3 text-2xl">Conseils d’entretien</h2>
            <div className="prose-amoria text-sm" dangerouslySetInnerHTML={{ __html: p.careInstructions }} />
          </div>
        )}
      </section>

      {!!related?.length && (
        <section className="mt-12">
          <h2 className="section-title mb-5">Vous aimerez aussi</h2>
          <ProductGrid products={related} />
        </section>
      )}
      <p className="sr-only">{formatFCFA(variant?.price ?? p.price)}</p>
    </div>
  );
}
