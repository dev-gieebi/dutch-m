import {
  AVAILABILITY_LABELS,
  AvailabilityMode,
  CategoryDto,
  formatFCFA,
  Paginated,
  Permission,
  ProductStatus,
} from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, Package, Plus, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Alert, Badge, DataTable, EmptyState, Field, Modal, PageLoader, Pagination, StatusBadge } from '@/admin/components/ui';
import { api, assetUrl, download, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';

interface ProductRow {
  id: number; name: string; reference: string; slug: string; status: ProductStatus;
  category: { id: number; name: string } | null; basePrice: number; currentPrice: number; onSale: boolean;
  availabilityMode: AvailabilityMode; computedAvailability: AvailabilityMode;
  isFeatured: boolean; isNew: boolean; isBestSeller: boolean; salesCount: number;
  stockOnHand: number; stockAvailable: number; variantCount: number; lowStock: boolean; thumbUrl: string | null; updatedAt: string;
}

export default function Products() {
  const user = useAuthStore((s) => s.user);
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [params, setParams] = useSearchParams();
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [bulk, setBulk] = useState<'applyPromotion' | 'setCategory' | null>(null);
  const [bulkForm, setBulkForm] = useState<Record<string, string>>({});
  const [bulkError, setBulkError] = useState<string | null>(null);
  const importRef = useRef<HTMLInputElement>(null);

  const q = {
    q: params.get('q') ?? '',
    categoryId: params.get('categoryId') ?? '',
    availability: params.get('availability') ?? '',
    status: params.get('status') ?? '',
    lowStock: params.get('lowStock') ?? '',
    page: Number(params.get('page') ?? 1),
    limit: 25,
  };
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v); else next.delete(k);
    next.delete('page');
    setParams(next, { replace: true });
  };

  const { data: categories } = useQuery({ queryKey: ['categories-admin'], queryFn: () => api<CategoryDto[]>('/admin/categories'), staleTime: 5 * 60_000 });
  const { data, isLoading } = useQuery({ queryKey: ['products', q], queryFn: () => api<Paginated<ProductRow>>('/admin/products', { query: q }), placeholderData: (p) => p });
  const canWrite = hasPermission(user, Permission.CATALOG_WRITE);
  const canPrices = hasPermission(user, Permission.PRICES_WRITE);

  const bulkMutate = useMutation({
    mutationFn: (body: Record<string, unknown>) => api('/admin/products/bulk', { method: 'POST', body }),
    onSuccess: () => { notify('Action appliquée'); setBulk(null); setSelected(new Set()); qc.invalidateQueries({ queryKey: ['products'] }); },
    onError: (e) => setBulkError(errorMessage(e)),
  });

  const doImport = async (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    try {
      const res = await api<{ created: number; updated: number; errors: string[] }>('/admin/products/import', { method: 'POST', body: fd });
      notify(`Import terminé : ${res.created} créés, ${res.updated} mis à jour${res.errors.length ? `, ${res.errors.length} erreur(s)` : ''}`, res.errors.length ? 'error' : 'success');
      qc.invalidateQueries({ queryKey: ['products'] });
    } catch (e) {
      notify(errorMessage(e), 'error');
    }
  };

  const toggle = (id: number) => setSelected((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n; });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Catalogue</h1>
        <div className="flex flex-wrap gap-2">
          <Link to="/admin/taxonomie" className="btn-secondary">Catégories & fournisseurs</Link>
          {canWrite && (
            <>
              <button className="btn-secondary" onClick={() => download('/admin/products/export.csv').catch((e) => notify(errorMessage(e), 'error'))}><Download className="h-4 w-4" /> CSV</button>
              <button className="btn-secondary" onClick={() => importRef.current?.click()}><Upload className="h-4 w-4" /> Importer</button>
              <input ref={importRef} type="file" accept=".csv" className="hidden" onChange={(e) => e.target.files?.[0] && doImport(e.target.files[0])} />
              <Link to="/admin/catalogue/nouveau" className="btn-primary"><Plus className="h-4 w-4" /> Nouveau produit</Link>
            </>
          )}
        </div>
      </div>

      <div className="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
        <input className="input" placeholder="Nom, référence, SKU…" value={q.q} onChange={(e) => set('q', e.target.value)} />
        <select className="input" value={q.categoryId} onChange={(e) => set('categoryId', e.target.value)}>
          <option value="">Catégorie</option>
          {categories?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select className="input" value={q.availability} onChange={(e) => set('availability', e.target.value)}>
          <option value="">Disponibilité</option>
          {Object.entries(AVAILABILITY_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <select className="input" value={q.status} onChange={(e) => set('status', e.target.value)}>
          <option value="">Statut</option>
          <option value="PUBLISHED">Publié</option><option value="DRAFT">Brouillon</option>
        </select>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!q.lowStock} onChange={(e) => set('lowStock', e.target.checked ? 'true' : '')} /> Stock faible</label>
      </div>

      {canWrite && selected.size > 0 && (
        <div className="card flex flex-wrap items-center gap-2 border-gold/40 p-3 text-sm">
          <span className="font-semibold">{selected.size} sélectionné(s)</span>
          <button className="btn-secondary !min-h-[32px] text-xs" onClick={() => bulkMutate.mutate({ ids: [...selected], action: 'publish' })}>Publier</button>
          <button className="btn-secondary !min-h-[32px] text-xs" onClick={() => bulkMutate.mutate({ ids: [...selected], action: 'unpublish' })}>Dépublier</button>
          <button className="btn-secondary !min-h-[32px] text-xs" onClick={() => { setBulk('setCategory'); setBulkForm({}); setBulkError(null); }}>Changer de catégorie</button>
          {canPrices && (
            <>
              <button className="btn-secondary !min-h-[32px] text-xs" onClick={() => { setBulk('applyPromotion'); setBulkForm({}); setBulkError(null); }}>Appliquer une promo</button>
              <button className="btn-secondary !min-h-[32px] text-xs" onClick={() => bulkMutate.mutate({ ids: [...selected], action: 'clearPromotion' })}>Retirer la promo</button>
            </>
          )}
          <button className="btn-danger !min-h-[32px] text-xs" onClick={() => confirm(`Supprimer ${selected.size} produit(s) ?`) && bulkMutate.mutate({ ids: [...selected], action: 'delete' })}>Supprimer</button>
          <button className="btn-ghost !min-h-[32px] text-xs" onClick={() => setSelected(new Set())}>Désélectionner</button>
        </div>
      )}

      {isLoading ? (
        <PageLoader />
      ) : !data?.data.length ? (
        <EmptyState icon={<Package className="h-10 w-10" />} title="Aucun produit" text="Aucun produit ne correspond à ces filtres." />
      ) : (
        <>
          <DataTable head={canWrite ? ['', 'Produit', 'Catégorie', 'Prix', 'Stock', 'Dispo', 'Statut', ''] : ['Produit', 'Catégorie', 'Prix', 'Stock', 'Dispo', 'Statut', '']}>
            {data.data.map((p) => (
              <tr key={p.id} className="hover:bg-ivory/60">
                {canWrite && (
                  <td className="td"><input type="checkbox" className="h-4 w-4 accent-gold" checked={selected.has(p.id)} onChange={() => toggle(p.id)} aria-label={`Sélectionner ${p.name}`} /></td>
                )}
                <td className="td">
                  <div className="flex items-center gap-2">
                    {p.thumbUrl ? <img src={assetUrl(p.thumbUrl)} alt="" className="h-9 w-9 rounded object-cover" /> : <span className="flex h-9 w-9 items-center justify-center rounded bg-sand text-xs text-ink-soft">?</span>}
                    <div>
                      <Link to={`/admin/catalogue/${p.id}`} className="font-medium text-gold-dark hover:underline">{p.name}</Link>
                      <p className="font-mono text-xs text-ink-soft">{p.reference}{p.variantCount > 1 ? ` · ${p.variantCount} variantes` : ''}</p>
                      <div className="flex gap-1">{p.isFeatured && <Badge tone="gold">Une</Badge>}{p.isNew && <Badge tone="success">Nouveau</Badge>}{p.isBestSeller && <Badge tone="rose">Top vente</Badge>}</div>
                    </div>
                  </div>
                </td>
                <td className="td text-xs">{p.category?.name ?? '—'}</td>
                <td className="td">{formatFCFA(p.currentPrice)}{p.onSale && <Badge tone="danger"> promo</Badge>}</td>
                <td className="td">
                  <span className={p.lowStock ? 'font-semibold text-danger' : ''}>{p.stockAvailable}</span>
                  <span className="text-xs text-ink-soft"> / {p.stockOnHand}</span>
                </td>
                <td className="td"><StatusBadge value={p.computedAvailability} /></td>
                <td className="td"><Badge tone={p.status === 'PUBLISHED' ? 'success' : 'muted'}>{p.status === 'PUBLISHED' ? 'Publié' : 'Brouillon'}</Badge></td>
                <td className="td"><Link to={`/admin/catalogue/${p.id}`} className="btn-ghost !min-h-[30px] !px-2 text-xs">Modifier</Link></td>
              </tr>
            ))}
          </DataTable>
          <Pagination page={q.page} limit={q.limit} total={data.meta.total} onPage={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); }} />
        </>
      )}

      <Modal open={bulk === 'setCategory'} onClose={() => setBulk(null)} title="Changer de catégorie">
        <Field label="Catégorie">
          <select className="input" value={bulkForm.categoryId ?? ''} onChange={(e) => setBulkForm({ ...bulkForm, categoryId: e.target.value })}>
            <option value="">—</option>
            {categories?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </Field>
        {bulkError && <Alert tone="error">{bulkError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={bulkMutate.isPending || !bulkForm.categoryId} onClick={() => bulkMutate.mutate({ ids: [...selected], action: 'setCategory', categoryId: Number(bulkForm.categoryId) })}>Appliquer</button>
      </Modal>
      <Modal open={bulk === 'applyPromotion'} onClose={() => setBulk(null)} title="Appliquer une promotion">
        <Field label="Remise (%)"><input className="input" inputMode="numeric" value={bulkForm.percent ?? ''} onChange={(e) => setBulkForm({ ...bulkForm, percent: e.target.value.replace(/\D/g, '') })} /></Field>
        <div className="mt-2 grid grid-cols-2 gap-3">
          <Field label="Début"><input className="input" type="datetime-local" value={bulkForm.startsAt ?? ''} onChange={(e) => setBulkForm({ ...bulkForm, startsAt: e.target.value })} /></Field>
          <Field label="Fin"><input className="input" type="datetime-local" value={bulkForm.endsAt ?? ''} onChange={(e) => setBulkForm({ ...bulkForm, endsAt: e.target.value })} /></Field>
        </div>
        {bulkError && <Alert tone="error">{bulkError}</Alert>}
        <button className="btn-primary mt-3 w-full" disabled={bulkMutate.isPending || !bulkForm.percent} onClick={() => bulkMutate.mutate({ ids: [...selected], action: 'applyPromotion', percent: Number(bulkForm.percent), startsAt: bulkForm.startsAt || undefined, endsAt: bulkForm.endsAt || undefined })}>Appliquer</button>
      </Modal>
    </div>
  );
}
