import { AvailabilityMode, CategoryDto, CollectionDto, FacetsDto, Paginated, ProductCardDto } from '@/shared';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { SlidersHorizontal, X } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ProductGrid, ProductGridSkeleton } from '@/components/ProductCard';
import { Breadcrumbs, EmptyState, Seo, Spinner } from '@/components/ui';
import { api } from '@/lib/api';
import NotFound from './NotFound';

type Mode = 'all' | 'category' | 'collection' | 'new' | 'sale' | 'search';

const SORTS = [
  ['relevance', 'Pertinence'],
  ['newest', 'Nouveautés'],
  ['price_asc', 'Prix croissant'],
  ['price_desc', 'Prix décroissant'],
  ['best_sellers', 'Meilleures ventes'],
] as const;

export function ProductListing({ mode, baseQuery, title }: { mode: Mode; baseQuery: Record<string, string | boolean | undefined>; title: string }) {
  const [params, setParams] = useSearchParams();
  const [filtersOpen, setFiltersOpen] = useState(false);
  const filters = {
    sort: params.get('sort') ?? (mode === 'new' ? 'newest' : 'relevance'),
    minPrice: params.get('minPrice') ?? '',
    maxPrice: params.get('maxPrice') ?? '',
    color: params.get('color') ?? '',
    material: params.get('material') ?? '',
    availability: params.get('availability') ?? '',
    onSale: params.get('onSale') === 'true',
  };
  const query = { ...baseQuery, ...filters, onSale: filters.onSale || baseQuery.onSale || undefined };
  const set = (key: string, value: string | boolean | null) => {
    const next = new URLSearchParams(params);
    if (value === null || value === '' || value === false) next.delete(key);
    else next.set(key, String(value));
    setParams(next, { replace: true });
  };
  const { data, isLoading, fetchNextPage, hasNextPage, isFetchingNextPage } = useInfiniteQuery({
    queryKey: ['products', query],
    queryFn: ({ pageParam }) => api<Paginated<ProductCardDto>>('/catalog/products', { query: { ...query, page: pageParam, limit: 12 } }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page * last.meta.limit < last.meta.total ? last.meta.page + 1 : undefined),
  });
  const { data: facets } = useQuery({
    queryKey: ['facets', baseQuery],
    queryFn: () => api<FacetsDto>('/catalog/facets', { query: baseQuery }),
    staleTime: 5 * 60_000,
  });
  const products = data?.pages.flatMap((p) => p.data) ?? [];
  const total = data?.pages[0]?.meta.total ?? 0;
  const activeCount = [filters.minPrice, filters.maxPrice, filters.color, filters.material, filters.availability, filters.onSale].filter(Boolean).length;

  const filterPanel = (
    <div className="space-y-5">
      <fieldset>
        <legend className="label">Disponibilité</legend>
        <div className="flex flex-wrap gap-2">
          {[['', 'Tout'], [AvailabilityMode.IN_STOCK, 'En stock'], [AvailabilityMode.PREORDER, 'Sur commande']].map(([v, l]) => (
            <button key={v} onClick={() => set('availability', v)} className={`badge min-h-[36px] px-3 ${filters.availability === v ? 'bg-gold text-white' : 'bg-sand'}`}>{l}</button>
          ))}
        </div>
      </fieldset>
      <fieldset>
        <legend className="label">Prix (FCFA)</legend>
        <div className="flex gap-2">
          <input className="input" inputMode="numeric" placeholder={facets ? String(facets.priceRange.min) : 'Min'} value={filters.minPrice} onChange={(e) => set('minPrice', e.target.value.replace(/\D/g, ''))} aria-label="Prix minimum" />
          <input className="input" inputMode="numeric" placeholder={facets ? String(facets.priceRange.max) : 'Max'} value={filters.maxPrice} onChange={(e) => set('maxPrice', e.target.value.replace(/\D/g, ''))} aria-label="Prix maximum" />
        </div>
      </fieldset>
      {!!facets?.colors.length && (
        <fieldset>
          <legend className="label">Couleur</legend>
          <div className="flex flex-wrap gap-2">
            {facets.colors.map((c) => (
              <button key={c.value} onClick={() => set('color', filters.color === c.value ? null : c.value)} className={`badge min-h-[36px] border px-3 ${filters.color === c.value ? 'border-gold bg-gold/10' : 'border-sand bg-white'}`}>
                {c.hexColor && <span className="h-3.5 w-3.5 rounded-full border border-sand" style={{ background: c.hexColor }} />}
                {c.value}
              </button>
            ))}
          </div>
        </fieldset>
      )}
      {!!facets?.materials.length && (
        <fieldset>
          <legend className="label">Matière</legend>
          <select className="input" value={filters.material} onChange={(e) => set('material', e.target.value)}>
            <option value="">Toutes</option>
            {facets.materials.map((m) => <option key={m} value={m}>{m}</option>)}
          </select>
        </fieldset>
      )}
      {mode !== 'sale' && (
        <label className="flex min-h-[44px] items-center gap-2 text-sm">
          <input type="checkbox" className="h-4 w-4 accent-gold" checked={filters.onSale} onChange={(e) => set('onSale', e.target.checked)} /> En promotion uniquement
        </label>
      )}
      {activeCount > 0 && (
        <button className="btn-ghost w-full" onClick={() => setParams(new URLSearchParams(filters.sort !== 'relevance' ? { sort: filters.sort } : {}), { replace: true })}>Réinitialiser les filtres</button>
      )}
    </div>
  );

  return (
    <div className="lg:grid lg:grid-cols-[240px_1fr] lg:gap-8">
      <aside className="hidden lg:block" aria-label="Filtres">{filterPanel}</aside>
      <div>
        <div className="mb-4 flex items-center justify-between gap-2">
          <p className="text-sm text-ink-soft">{isLoading ? 'Chargement…' : `${total} produit${total > 1 ? 's' : ''}`}</p>
          <div className="flex items-center gap-2">
            <button className="btn-secondary !min-h-[40px] lg:hidden" onClick={() => setFiltersOpen(true)}>
              <SlidersHorizontal className="h-4 w-4" /> Filtres{activeCount ? ` (${activeCount})` : ''}
            </button>
            <label className="sr-only" htmlFor="sort">Trier par</label>
            <select id="sort" className="input !w-auto !min-h-[40px]" value={filters.sort} onChange={(e) => set('sort', e.target.value)}>
              {SORTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
        </div>
        {isLoading ? (
          <ProductGridSkeleton />
        ) : products.length ? (
          <>
            <ProductGrid products={products} />
            {hasNextPage && (
              <div className="mt-8 flex justify-center">
                <button className="btn-secondary" onClick={() => fetchNextPage()} disabled={isFetchingNextPage}>
                  {isFetchingNextPage ? <Spinner /> : `Voir plus (${total - products.length} restants)`}
                </button>
              </div>
            )}
          </>
        ) : (
          <EmptyState title="Aucun produit trouvé" text={`Essayez d’élargir vos critères dans « ${title} ».`} action={<Link to="/boutique" className="btn-primary">Voir toute la boutique</Link>} />
        )}
      </div>
      {filtersOpen && (
        <div className="fixed inset-0 z-50 flex justify-end bg-ink/40 lg:hidden" onClick={() => setFiltersOpen(false)}>
          <div className="h-full w-[85%] max-w-sm overflow-y-auto bg-ivory p-5 animate-fade-in" onClick={(e) => e.stopPropagation()} role="dialog" aria-label="Filtres">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-2xl">Filtres</h2>
              <button onClick={() => setFiltersOpen(false)} className="p-2" aria-label="Fermer"><X /></button>
            </div>
            {filterPanel}
            <button className="btn-primary mt-6 w-full" onClick={() => setFiltersOpen(false)}>Voir {total} produit{total > 1 ? 's' : ''}</button>
          </div>
        </div>
      )}
    </div>
  );
}

export default function Catalog({ mode }: { mode: Mode }) {
  const { slug } = useParams();
  const category = useQuery({
    queryKey: ['category', slug],
    queryFn: () => api<CategoryDto & { children: CategoryDto[]; breadcrumbs: CategoryDto[] }>(`/catalog/categories/${slug}`),
    enabled: mode === 'category',
  });
  const collection = useQuery({
    queryKey: ['collection', slug],
    queryFn: () => api<CollectionDto & { seoTitle: string | null; seoDescription: string | null }>(`/catalog/collections/${slug}`),
    enabled: mode === 'collection',
  });
  if ((mode === 'category' && category.isError) || (mode === 'collection' && collection.isError)) return <NotFound />;

  const meta = {
    all: { title: 'La boutique', description: 'Toutes nos fleurs, compositions et objets de décoration.', query: {} },
    new: { title: 'Nouveautés', description: 'Les dernières créations DutCh’M.', query: { isNew: true } },
    sale: { title: 'Promotions', description: 'Nos offres du moment.', query: { onSale: true } },
    category: { title: category.data?.name ?? '', description: category.data?.description ?? '', query: { category: slug ?? '' } },
    collection: { title: collection.data?.name ?? '', description: collection.data?.description ?? '', query: { collection: slug ?? '' } },
    search: { title: 'Recherche', description: '', query: {} },
  }[mode];

  return (
    <div className="container-page py-6">
      <Seo
        title={category.data?.seoTitle ?? collection.data?.seoTitle ?? meta.title}
        description={category.data?.seoDescription ?? collection.data?.seoDescription ?? meta.description}
      />
      <Breadcrumbs
        items={
          mode === 'category' && category.data
            ? [{ label: 'Boutique', to: '/boutique' }, ...category.data.breadcrumbs.map((b, i, arr) => ({ label: b.name, to: i < arr.length - 1 ? `/categorie/${b.slug}` : undefined }))]
            : [{ label: meta.title }]
        }
      />
      <header className="mb-6">
        <h1 className="section-title !text-3xl sm:!text-4xl">{meta.title || '…'}</h1>
        {meta.description && <p className="mt-2 max-w-2xl text-sm text-ink-soft">{meta.description}</p>}
        {!!category.data?.children.length && (
          <div className="no-scrollbar mt-4 flex gap-2 overflow-x-auto">
            {category.data.children.map((c) => <Link key={c.id} to={`/categorie/${c.slug}`} className="badge min-h-[36px] whitespace-nowrap bg-ivory px-4 shadow-soft">{c.name}</Link>)}
          </div>
        )}
      </header>
      {(mode !== 'category' || category.data) && (mode !== 'collection' || collection.data) ? (
        <ProductListing mode={mode} baseQuery={meta.query} title={meta.title} />
      ) : (
        <ProductGridSkeleton />
      )}
    </div>
  );
}
