import { BannerDto, CollectionDto, Paginated, ProductCardDto } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Flower2, HandCoins, MessageCircle, Smartphone, Star, Truck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ProductGrid, ProductGridSkeleton } from '@/components/ProductCard';
import { Seo, Skeleton } from '@/components/ui';
import { api, assetUrl } from '@/lib/api';

function useProducts(key: string, query: Record<string, string | number | boolean>) {
  return useQuery({ queryKey: ['home', key], queryFn: () => api<Paginated<ProductCardDto>>('/catalog/products', { query: { limit: 8, ...query } }) });
}

function HeroCarousel() {
  const { data, isLoading } = useQuery({ queryKey: ['banners'], queryFn: () => api<BannerDto[]>('/content/banners') });
  const [index, setIndex] = useState(0);
  const banners = data ?? [];
  useEffect(() => {
    if (banners.length < 2) return;
    const t = setInterval(() => setIndex((i) => (i + 1) % banners.length), 6000);
    return () => clearInterval(t);
  }, [banners.length]);
  if (isLoading) return <Skeleton className="aspect-[4/5] w-full sm:aspect-[21/9]" />;
  if (!banners.length) return null;
  const b = banners[index];
  return (
    <section className="relative overflow-hidden rounded-xl" aria-roledescription="carrousel" aria-label="Mises en avant">
      <picture>
        {b.imageMobileUrl && <source media="(max-width: 640px)" srcSet={assetUrl(b.imageMobileUrl)} />}
        <img src={assetUrl(b.imageDesktopUrl)} alt={b.title} className="aspect-[4/5] w-full object-cover sm:aspect-[21/9]" />
      </picture>
      <div className="absolute inset-0 flex flex-col items-start justify-end gap-3 bg-gradient-to-t from-ink/60 via-ink/10 to-transparent p-6 sm:justify-center sm:bg-gradient-to-r sm:p-12">
        <h1 className="max-w-md font-serif text-3xl font-bold text-white sm:text-5xl">{b.title}</h1>
        {b.subtitle && <p className="max-w-md text-sm text-white/90 sm:text-base">{b.subtitle}</p>}
        {b.linkUrl && <Link to={b.linkUrl} className="btn-primary">{b.ctaLabel ?? 'Découvrir'}</Link>}
      </div>
      {banners.length > 1 && (
        <>
          <button className="absolute left-2 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-ivory/80 sm:flex" onClick={() => setIndex((index - 1 + banners.length) % banners.length)} aria-label="Précédent"><ChevronLeft /></button>
          <button className="absolute right-2 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-ivory/80 sm:flex" onClick={() => setIndex((index + 1) % banners.length)} aria-label="Suivant"><ChevronRight /></button>
          <div className="absolute bottom-3 right-4 flex gap-1.5">
            {banners.map((x, i) => (
              <button key={x.id} onClick={() => setIndex(i)} className={`h-2 rounded-full transition-all ${i === index ? 'w-6 bg-white' : 'w-2 bg-white/60'}`} aria-label={`Bannière ${i + 1}`} />
            ))}
          </div>
        </>
      )}
    </section>
  );
}

function Section({ title, link, query, keyName }: { title: string; link: string; query: Record<string, string | number | boolean>; keyName: string }) {
  const { data, isLoading } = useProducts(keyName, query);
  if (!isLoading && !data?.data.length) return null;
  return (
    <section className="container-page mt-12">
      <div className="mb-5 flex items-end justify-between gap-4">
        <h2 className="section-title">{title}</h2>
        <Link to={link} className="text-sm font-semibold text-gold-dark hover:underline">Tout voir</Link>
      </div>
      {isLoading ? <ProductGridSkeleton count={4} /> : <ProductGrid products={data!.data.slice(0, 8)} />}
    </section>
  );
}

function Collections() {
  const { data } = useQuery({ queryKey: ['collections'], queryFn: () => api<CollectionDto[]>('/catalog/collections') });
  if (!data?.length) return null;
  return (
    <section className="container-page mt-12">
      <h2 className="section-title mb-5">Nos collections</h2>
      <div className="no-scrollbar flex snap-x gap-4 overflow-x-auto pb-2">
        {data.map((c) => (
          <Link key={c.id} to={`/collection/${c.slug}`} className="card group min-w-[240px] flex-1 snap-start overflow-hidden p-6 transition hover:-translate-y-1">
            <Flower2 className="mb-3 h-8 w-8 text-rose" />
            <h3 className="text-2xl">{c.name}</h3>
            {c.description && <p className="mt-2 line-clamp-2 text-sm text-ink-soft">{c.description}</p>}
            <span className="mt-4 inline-block text-sm font-semibold text-gold-dark group-hover:underline">Découvrir →</span>
          </Link>
        ))}
      </div>
    </section>
  );
}

function Reviews() {
  const { data } = useQuery({
    queryKey: ['reviews-highlights'],
    queryFn: () => api<{ id: number; authorName: string; rating: number; comment: string | null; productName: string | null }[]>('/catalog/reviews/highlights'),
  });
  if (!data?.length) return null;
  return (
    <section className="container-page mt-12">
      <h2 className="section-title mb-5">Elles nous ont fait confiance</h2>
      <div className="grid gap-4 md:grid-cols-3">
        {data.slice(0, 3).map((r) => (
          <figure key={r.id} className="card p-5">
            <div className="flex gap-0.5 text-gold" aria-label={`${r.rating} sur 5`}>
              {Array.from({ length: 5 }).map((_, i) => <Star key={i} className={`h-4 w-4 ${i < r.rating ? 'fill-current' : ''}`} />)}
            </div>
            <blockquote className="mt-3 text-sm italic">« {r.comment} »</blockquote>
            <figcaption className="mt-3 text-xs text-ink-soft">{r.authorName}{r.productName ? ` · ${r.productName}` : ''}</figcaption>
          </figure>
        ))}
      </div>
    </section>
  );
}

export default function Home() {
  return (
    <>
      <Seo title="DutCh’M – La Fleuriste du Bien-être" description="Fleurs artificielles, fleurs vintage DutCh’M Héritage, compositions florales, vases, bougies et coffrets bien-être. Livraison à Libreville." />
      <div className="container-page pt-4"><HeroCarousel /></div>
      <Collections />
      <Section title="Nouveautés" link="/nouveautes" query={{ isNew: true, sort: 'newest' }} keyName="new" />
      <Section title="Promotions" link="/promotions" query={{ onSale: true }} keyName="sale" />
      <Section title="Meilleures ventes" link="/boutique?sort=best_sellers" query={{ sort: 'best_sellers' }} keyName="best" />
      <section className="mt-16 bg-sand py-14">
        <div className="container-page grid items-center gap-8 md:grid-cols-2">
          <div>
            <h2 className="section-title">Notre univers</h2>
            <p className="mt-4 leading-relaxed text-ink-soft">
              Chez DutCh’M, nous croyons que la beauté d’un intérieur nourrit le bien-être. Nos fleurs éternelles, nos compositions et nos objets de décoration
              sont choisis pour apporter douceur, sérénité et élégance à votre quotidien – sans entretien, toute l’année.
            </p>
            <Link to="/a-propos" className="btn-secondary mt-6">Découvrir notre histoire</Link>
          </div>
          <div className="grid grid-cols-2 gap-3">
            {['Fleurs éternelles', 'Gamme Héritage', 'Compositions', 'Bien-être'].map((t, i) => (
              <div key={t} className={`flex aspect-square items-center justify-center rounded-xl p-4 text-center font-serif text-xl ${i % 2 ? 'bg-blush text-rose-dark' : 'bg-ivory text-gold-dark'}`}>{t}</div>
            ))}
          </div>
        </div>
      </section>
      <Reviews />
      <section className="container-page mt-12" aria-label="Nos engagements">
        <ul className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {[
            [Truck, 'Livraison à Libreville', 'Sous 24–48 h pour les articles en stock'],
            [Smartphone, 'Paiement mobile', 'Airtel Money et Moov Money'],
            [HandCoins, 'Paiement à la livraison', 'Espèces ou mobile money à la remise'],
            [MessageCircle, 'Service client WhatsApp', 'Une équipe à votre écoute'],
          ].map(([Icon, title, text]) => {
            const I = Icon as typeof Truck;
            return (
              <li key={title as string} className="card flex flex-col items-center gap-2 p-4 text-center">
                <I className="h-7 w-7 text-gold" aria-hidden="true" />
                <p className="text-sm font-semibold">{title as string}</p>
                <p className="text-xs text-ink-soft">{text as string}</p>
              </li>
            );
          })}
        </ul>
      </section>
    </>
  );
}
