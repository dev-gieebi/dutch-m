import { formatFCFA, ProductCardDto } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { Search, X } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { api, assetUrl } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { Spinner } from './ui';

interface SearchResult {
  products: ProductCardDto[];
  total: number;
  categories: { id: number; name: string; slug: string }[];
}

export function SearchBox({ autoFocus = false }: { autoFocus?: boolean }) {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const debounced = useDebounce(q.trim(), 250);
  const navigate = useNavigate();
  const location = useLocation();
  const ref = useRef<HTMLDivElement>(null);
  const { data, isFetching } = useQuery({
    queryKey: ['search', debounced],
    queryFn: () => api<SearchResult>('/catalog/search', { query: { q: debounced } }),
    enabled: debounced.length >= 2,
    staleTime: 60_000,
  });

  useEffect(() => { setOpen(false); }, [location.pathname]);
  useEffect(() => {
    const close = (e: MouseEvent) => ref.current && !ref.current.contains(e.target as Node) && setOpen(false);
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (q.trim().length < 2) return;
    setOpen(false);
    navigate(`/recherche?q=${encodeURIComponent(q.trim())}`);
  };

  return (
    <div ref={ref} className="relative w-full">
      <form onSubmit={submit} role="search">
        <label htmlFor="search-input" className="sr-only">
          Rechercher un produit
        </label>
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
        <input
          id="search-input"
          type="search"
          autoFocus={autoFocus}
          autoComplete="off"
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          placeholder="Rechercher une fleur, un vase, une bougie…"
          className="input !rounded-full !pl-9 !pr-9"
        />
        {q && (
          <button type="button" onClick={() => setQ('')} className="absolute right-2 top-1/2 -translate-y-1/2 p-2 text-ink-soft" aria-label="Effacer">
            {isFetching ? <Spinner className="h-4 w-4" /> : <X className="h-4 w-4" />}
          </button>
        )}
      </form>
      {open && debounced.length >= 2 && data && (
        <div className="absolute inset-x-0 top-full z-40 mt-2 max-h-[70vh] overflow-y-auto rounded-xl bg-ivory p-2 shadow-soft animate-fade-in">
          {data.categories.length > 0 && (
            <div className="mb-2 flex flex-wrap gap-2 px-2 pt-1">
              {data.categories.map((c) => (
                <Link key={c.id} to={`/categorie/${c.slug}`} className="badge bg-sand text-ink hover:bg-blush">
                  {c.name}
                </Link>
              ))}
            </div>
          )}
          {data.products.length === 0 ? (
            <p className="px-3 py-4 text-sm text-ink-soft">Aucun résultat pour « {debounced} ».</p>
          ) : (
            <ul>
              {data.products.map((p) => (
                <li key={p.id}>
                  <Link to={`/produit/${p.slug}`} className="flex items-center gap-3 rounded-lg p-2 hover:bg-sand">
                    <img src={assetUrl(p.image?.thumbUrl)} alt="" className="h-12 w-12 rounded-lg object-cover" loading="lazy" />
                    <span className="flex-1 text-sm">{p.name}</span>
                    <span className="text-sm font-semibold text-gold-dark">{formatFCFA(p.price)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          )}
          {data.total > data.products.length && (
            <button onClick={submit} className="btn-ghost w-full text-gold-dark">
              Voir les {data.total} résultats
            </button>
          )}
        </div>
      )}
    </div>
  );
}
