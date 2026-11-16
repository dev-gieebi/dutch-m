import {
  AttributeType,
  AvailabilityMode,
  AVAILABILITY_LABELS,
  CategoryDto,
  DEFAULT_LEAD_TIME_DAYS,
  formatFCFA,
  Permission,
  ProductStatus,
  slugify,
} from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, ImagePlus, Plus, Save, Star, Trash2, Wand2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Alert, Badge, Field, Modal, PageLoader } from '@/admin/components/ui';
import { api, assetUrl, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';

interface AttributeValue { id: number; attributeId: number; value: string; hexColor: string | null; sortOrder: number }
interface Attribute { id: number; name: string; code: string; type: AttributeType; values: AttributeValue[] }
interface Supplier { id: number; name: string; country: string; usualLeadTimeDays: number; isActive: boolean }
interface CollectionRow { id: number; name: string }

interface VariantRow {
  id: number; sku: string; name: string; price: number; compareAtPrice: number | null; promoPrice: number | null;
  promoStartsAt: string | null; promoEndsAt: string | null; costPrice: number | null; stockOnHand: number;
  stockReserved: number; stockAvailable: number; lowStockThreshold: number; availabilityMode: AvailabilityMode | null;
  isDefault: boolean; isActive: boolean; sortOrder: number; attributeValueIds: number[];
}

interface ImageRow { id: number; url: string; mediumUrl: string; thumbUrl: string; alt: string; sortOrder: number; isMain: boolean; variantId: number | null }

interface ProductData {
  id: number; name: string; slug: string; reference: string; categoryId: number | null; collectionIds: number[];
  shortDescription: string | null; description: string | null; dimensions: string | null; material: string | null;
  colors: string[] | null; careInstructions: string | null; weight: number | null;
  basePrice: number; compareAtPrice: number | null; costPrice: number | null;
  availabilityMode: AvailabilityMode; computedAvailability: AvailabilityMode; leadTimeDays: number;
  depositPercent: number | null; fullPaymentRequired: boolean; autoPreorderWhenOutOfStock: boolean;
  maxPreorderQty: number | null; supplierId: number | null; relatedProductIds: number[];
  relatedProducts: { id: number; name: string; reference: string }[];
  seoTitle: string | null; seoDescription: string | null; status: ProductStatus;
  isFeatured: boolean; isNew: boolean; isBestSeller: boolean;
  variants: VariantRow[]; images: ImageRow[];
}

type Form = Record<string, unknown>;
const num = (v: unknown) => (v === '' || v === undefined || v === null ? undefined : Number(v));
const numOrNull = (v: unknown) => (v === '' || v === undefined || v === null ? null : Number(v));

const EMPTY: Form = {
  name: '', slug: '', reference: '', categoryId: '', collectionIds: [], shortDescription: '', description: '',
  dimensions: '', material: '', colors: [], careInstructions: '', weight: '',
  basePrice: '', compareAtPrice: '', costPrice: '', availabilityMode: AvailabilityMode.IN_STOCK,
  leadTimeDays: DEFAULT_LEAD_TIME_DAYS, depositPercent: '', fullPaymentRequired: false, autoPreorderWhenOutOfStock: false,
  maxPreorderQty: '', supplierId: '', relatedProductIds: [], seoTitle: '', seoDescription: '',
  status: ProductStatus.DRAFT, isFeatured: false, isNew: false, isBestSeller: false, initialStock: 0,
};

const TABS = ['Général', 'Prix & précommande', 'Variantes', 'Images', 'SEO'] as const;

export default function ProductForm() {
  const { id } = useParams();
  const isNew = !id;
  const navigate = useNavigate();
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const user = useAuthStore((s) => s.user);
  const canPrices = hasPermission(user, Permission.PRICES_WRITE);
  const [tab, setTab] = useState<(typeof TABS)[number]>('Général');
  const [form, setForm] = useState<Form>(EMPTY);
  const [variants, setVariants] = useState<VariantRow[]>([]);
  const [images, setImages] = useState<ImageRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [variantModal, setVariantModal] = useState<Partial<VariantRow> | null>(null);
  const [generateOpen, setGenerateOpen] = useState(false);
  const [searchRelated, setSearchRelated] = useState('');
  const [relatedPicked, setRelatedPicked] = useState<{ id: number; name: string; reference: string }[]>([]);
  const [newColor, setNewColor] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  const { data: categories } = useQuery({ queryKey: ['categories-admin'], queryFn: () => api<(CategoryDto & { parentId?: number | null })[]>('/admin/categories'), staleTime: 5 * 60_000 });
  const { data: collections } = useQuery({ queryKey: ['collections-admin'], queryFn: () => api<CollectionRow[]>('/admin/collections'), staleTime: 5 * 60_000 });
  const { data: suppliers } = useQuery({ queryKey: ['suppliers-admin'], queryFn: () => api<Supplier[]>('/admin/suppliers'), staleTime: 5 * 60_000 });
  const { data: attributes } = useQuery({ queryKey: ['attributes-admin'], queryFn: () => api<Attribute[]>('/admin/attributes'), staleTime: 5 * 60_000 });
  const { data: relatedResults } = useQuery({
    queryKey: ['related-search', searchRelated],
    queryFn: () => api<{ data: { id: number; name: string; reference: string }[] }>('/admin/products', { query: { q: searchRelated, limit: 8 } }),
    enabled: searchRelated.length >= 2,
  });

  const { data: product, isLoading } = useQuery({
    queryKey: ['product', id],
    queryFn: () => api<ProductData>(`/admin/products/${id}`),
    enabled: !isNew,
  });

  useEffect(() => {
    if (!product) return;
    setForm({
      name: product.name, slug: product.slug, reference: product.reference, categoryId: product.categoryId ?? '',
      collectionIds: product.collectionIds, shortDescription: product.shortDescription ?? '', description: product.description ?? '',
      dimensions: product.dimensions ?? '', material: product.material ?? '', colors: product.colors ?? [],
      careInstructions: product.careInstructions ?? '', weight: product.weight ?? '', basePrice: product.basePrice,
      compareAtPrice: product.compareAtPrice ?? '', costPrice: product.costPrice ?? '', availabilityMode: product.availabilityMode,
      leadTimeDays: product.leadTimeDays, depositPercent: product.depositPercent ?? '', fullPaymentRequired: product.fullPaymentRequired,
      autoPreorderWhenOutOfStock: product.autoPreorderWhenOutOfStock, maxPreorderQty: product.maxPreorderQty ?? '',
      supplierId: product.supplierId ?? '', seoTitle: product.seoTitle ?? '', seoDescription: product.seoDescription ?? '',
      status: product.status, isFeatured: product.isFeatured, isNew: product.isNew, isBestSeller: product.isBestSeller, initialStock: 0,
    });
    setVariants(product.variants);
    setImages(product.images);
    setRelatedPicked(product.relatedProducts);
  }, [product]);

  const set = (k: string, v: unknown) => setForm((f) => ({ ...f, [k]: v }));
  const refresh = () => { if (!isNew) void qc.invalidateQueries({ queryKey: ['product', id] }); };

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = {
        name: form.name, slug: form.slug || undefined, reference: form.reference || undefined,
        categoryId: numOrNull(form.categoryId), collectionIds: form.collectionIds,
        shortDescription: form.shortDescription || null, description: form.description || null,
        dimensions: form.dimensions || null, material: form.material || null, colors: form.colors,
        careInstructions: form.careInstructions || null, weight: numOrNull(form.weight),
        availabilityMode: form.availabilityMode, leadTimeDays: num(form.leadTimeDays),
        depositPercent: numOrNull(form.depositPercent), fullPaymentRequired: form.fullPaymentRequired,
        autoPreorderWhenOutOfStock: form.autoPreorderWhenOutOfStock, maxPreorderQty: numOrNull(form.maxPreorderQty),
        supplierId: numOrNull(form.supplierId), relatedProductIds: relatedPicked.map((p) => p.id),
        seoTitle: form.seoTitle || null, seoDescription: form.seoDescription || null,
        status: form.status, isFeatured: form.isFeatured, isNew: form.isNew, isBestSeller: form.isBestSeller,
      };
      if (canPrices) {
        body.basePrice = num(form.basePrice); body.compareAtPrice = numOrNull(form.compareAtPrice); body.costPrice = numOrNull(form.costPrice);
      }
      if (isNew) body.initialStock = num(form.initialStock) ?? 0;
      return isNew ? api<ProductData>('/admin/products', { method: 'POST', body }) : api<ProductData>(`/admin/products/${id}`, { method: 'PATCH', body });
    },
    onSuccess: (p) => { notify('Produit enregistré'); qc.invalidateQueries({ queryKey: ['products'] }); if (isNew) navigate(`/admin/catalogue/${p.id}`, { replace: true }); },
    onError: (e) => setError(errorMessage(e)),
  });

  const saveVariant = useMutation({
    mutationFn: (v: Partial<VariantRow>) =>
      v.id
        ? api(`/admin/products/variants/${v.id}`, { method: 'PATCH', body: v })
        : api(`/admin/products/${id}/variants`, { method: 'POST', body: v }),
    onSuccess: () => { notify('Variante enregistrée'); setVariantModal(null); refresh(); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });
  const removeVariant = useMutation({
    mutationFn: (variantId: number) => api(`/admin/products/variants/${variantId}`, { method: 'DELETE' }),
    onSuccess: () => { notify('Variante supprimée'); refresh(); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });
  const generate = useMutation({
    mutationFn: (body: { attributeValueIds: number[]; price?: number }) => api(`/admin/products/${id}/variants/generate`, { method: 'POST', body }),
    onSuccess: () => { notify('Variantes générées'); setGenerateOpen(false); refresh(); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });
  const removeImage = useMutation({
    mutationFn: (imageId: number) => api(`/admin/products/images/${imageId}`, { method: 'DELETE' }),
    onSuccess: () => refresh(),
    onError: (e) => notify(errorMessage(e), 'error'),
  });

  const uploadImage = async (file: File) => {
    if (isNew) return notify('Enregistrez le produit avant d\'ajouter des images', 'error');
    const fd = new FormData();
    fd.append('file', file);
    try {
      const up = await api<{ url: string; mediumUrl: string; thumbUrl: string }>('/admin/uploads', { method: 'POST', body: fd });
      await api(`/admin/products/${id}/images`, { method: 'POST', body: { ...up, isMain: images.length === 0 } });
      refresh();
    } catch (e) {
      notify(errorMessage(e), 'error');
    }
  };

  const setMainImage = (imageId: number) => api(`/admin/products/images/${imageId}`, { method: 'PATCH', body: { isMain: true } }).then(refresh).catch((e) => notify(errorMessage(e), 'error'));
  const [genChecked, setGenChecked] = useState<Set<number>>(new Set());

  if (!isNew && isLoading) return <PageLoader />;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Link to="/admin/catalogue" className="btn-ghost !px-2"><ArrowLeft className="h-4 w-4" /></Link>
        <h1 className="text-2xl">{isNew ? 'Nouveau produit' : String(form.name || 'Produit')}</h1>
        {product && <Badge tone={product.status === 'PUBLISHED' ? 'success' : 'muted'}>{product.status === 'PUBLISHED' ? 'Publié' : 'Brouillon'}</Badge>}
        <div className="ml-auto flex gap-2">
          {product && <a href={`${(import.meta.env.VITE_SITE_URL as string) ?? ''}/produit/${product.slug}`} target="_blank" rel="noreferrer" className="btn-secondary text-xs">Voir la fiche boutique</a>}
          <button className="btn-primary" onClick={() => save.mutate()} disabled={save.isPending}><Save className="h-4 w-4" /> Enregistrer</button>
        </div>
      </div>
      {error && <Alert tone="error">{error}</Alert>}
      {product?.computedAvailability && product.computedAvailability !== form.availabilityMode && (
        <Alert tone="warning">Disponibilité calculée : {AVAILABILITY_LABELS[product.computedAvailability]} (selon les variantes et le stock).</Alert>
      )}
      <div className="flex gap-1 overflow-x-auto rounded-lg bg-sand/50 p-1" role="tablist">
        {TABS.map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)} className={`whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold ${tab === t ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}>{t}</button>
        ))}
      </div>

      {tab === 'Général' && (
        <div className="card grid gap-4 p-5 lg:grid-cols-2">
          <Field label="Nom"><input className="input" value={String(form.name)} onChange={(e) => { set('name', e.target.value); if (isNew && !form.slug) set('slug', slugify(e.target.value)); }} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Slug"><input className="input font-mono text-xs" value={String(form.slug)} onChange={(e) => set('slug', slugify(e.target.value))} placeholder="auto" /></Field>
            <Field label="Référence"><input className="input font-mono text-xs" value={String(form.reference)} onChange={(e) => set('reference', e.target.value.toUpperCase())} placeholder="auto" /></Field>
          </div>
          <Field label="Catégorie">
            <select className="input" value={String(form.categoryId)} onChange={(e) => set('categoryId', e.target.value)}>
              <option value="">—</option>
              {categories?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <Field label="Collections">
            <div className="flex flex-wrap gap-2 rounded-lg border border-sand p-2">
              {collections?.map((c) => {
                const list = (form.collectionIds as number[]).includes(c.id);
                return (
                  <button key={c.id} type="button" onClick={() => set('collectionIds', list ? (form.collectionIds as number[]).filter((x) => x !== c.id) : [...(form.collectionIds as number[]), c.id])} className={`badge ${list ? 'bg-gold/25 text-gold-dark' : 'bg-sand text-ink-soft'}`}>{c.name}</button>
                );
              })}
              {!collections?.length && <span className="text-xs text-ink-soft">Aucune collection — créez-en une dans « Taxonomie ».</span>}
            </div>
          </Field>
          <Field label="Description courte"><textarea className="input" rows={2} maxLength={500} value={String(form.shortDescription)} onChange={(e) => set('shortDescription', e.target.value)} /></Field>
          <Field label="Description longue"><textarea className="input" rows={4} value={String(form.description)} onChange={(e) => set('description', e.target.value)} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Dimensions"><input className="input" value={String(form.dimensions)} onChange={(e) => set('dimensions', e.target.value)} placeholder="Ex. 60 cm" /></Field>
            <Field label="Matière"><input className="input" value={String(form.material)} onChange={(e) => set('material', e.target.value)} placeholder="Ex. Soie artificielle" /></Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Poids (g)"><input className="input" inputMode="numeric" value={String(form.weight)} onChange={(e) => set('weight', e.target.value.replace(/\D/g, ''))} /></Field>
            <Field label="Conseils d'entretien"><input className="input" value={String(form.careInstructions)} onChange={(e) => set('careInstructions', e.target.value)} /></Field>
          </div>
          <Field label="Couleurs">
            <div className="flex flex-wrap items-center gap-2 rounded-lg border border-sand p-2">
              {(form.colors as string[]).map((c) => (
                <span key={c} className="badge bg-rose/20 text-rose-dark">{c} <button type="button" onClick={() => set('colors', (form.colors as string[]).filter((x) => x !== c))} aria-label={`Retirer ${c}`}><X className="h-3 w-3" /></button></span>
              ))}
              <input className="min-w-[100px] flex-1 border-none bg-transparent text-sm outline-none" value={newColor} onChange={(e) => setNewColor(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && newColor.trim()) { e.preventDefault(); set('colors', [...new Set([...(form.colors as string[]), newColor.trim()])]); setNewColor(''); } }} placeholder="Ajouter + Entrée" />
            </div>
          </Field>
          <div className="flex flex-col justify-end gap-2">
            {(['isFeatured', 'isNew', 'isBestSeller'] as const).map((k) => (
              <label key={k} className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="h-4 w-4 accent-gold" checked={!!form[k]} onChange={(e) => set(k, e.target.checked)} />
                {{ isFeatured: 'À la une', isNew: 'Nouveauté', isBestSeller: 'Meilleure vente' }[k]}
              </label>
            ))}
            <Field label="Statut">
              <select className="input" value={String(form.status)} onChange={(e) => set('status', e.target.value)}>
                <option value="DRAFT">Brouillon</option><option value="PUBLISHED">Publié</option>
              </select>
            </Field>
          </div>
          <Field label="Produits associés" hint="Suggérés sur la fiche produit">
            <div className="space-y-2 rounded-lg border border-sand p-2">
              {relatedPicked.map((p) => (
                <span key={p.id} className="badge mr-1 bg-sand text-ink">{p.name} <button type="button" onClick={() => setRelatedPicked(relatedPicked.filter((x) => x.id !== p.id))} aria-label={`Retirer ${p.name}`}><X className="h-3 w-3" /></button></span>
              ))}
              <input className="input !mt-1" placeholder="Rechercher un produit…" value={searchRelated} onChange={(e) => setSearchRelated(e.target.value)} />
              {!!relatedResults?.data.length && (
                <ul className="max-h-32 overflow-y-auto text-sm">
                  {relatedResults.data.filter((p) => !relatedPicked.some((r) => r.id === p.id)).map((p) => (
                    <li key={p.id}><button type="button" className="w-full rounded px-2 py-1 text-left hover:bg-ivory" onClick={() => { setRelatedPicked([...relatedPicked, p]); setSearchRelated(''); }}>{p.name} <span className="text-xs text-ink-soft">({p.reference})</span></button></li>
                  ))}
                </ul>
              )}
            </div>
          </Field>
        </div>
      )}

      {tab === 'Prix & précommande' && (
        <div className="card grid gap-4 p-5 lg:grid-cols-2">
          {!canPrices && <Alert tone="warning" >Votre rôle ne permet pas de modifier les prix — les champs de prix sont en lecture seule.</Alert>}
          <Field label="Prix de base (FCFA)"><input className="input" inputMode="numeric" value={String(form.basePrice)} onChange={(e) => set('basePrice', e.target.value.replace(/\D/g, ''))} disabled={!canPrices} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Prix barré"><input className="input" inputMode="numeric" value={String(form.compareAtPrice)} onChange={(e) => set('compareAtPrice', e.target.value.replace(/\D/g, ''))} disabled={!canPrices} /></Field>
            <Field label="Coût d'achat"><input className="input" inputMode="numeric" value={String(form.costPrice)} onChange={(e) => set('costPrice', e.target.value.replace(/\D/g, ''))} disabled={!canPrices} /></Field>
          </div>
          <Field label="Disponibilité">
            <select className="input" value={String(form.availabilityMode)} onChange={(e) => set('availabilityMode', e.target.value)}>
              {Object.entries(AVAILABILITY_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </Field>
          <Field label="Fournisseur">
            <select className="input" value={String(form.supplierId)} onChange={(e) => set('supplierId', e.target.value)}>
              <option value="">—</option>
              {suppliers?.filter((s) => s.isActive).map((s) => <option key={s.id} value={s.id}>{s.name} ({s.country}, ~{s.usualLeadTimeDays} j)</option>)}
            </select>
          </Field>
          {isNew && <Field label="Stock initial"><input className="input" inputMode="numeric" value={String(form.initialStock)} onChange={(e) => set('initialStock', e.target.value.replace(/\D/g, ''))} /></Field>}
          <div className="card grid gap-3 bg-ivory/60 p-4 lg:col-span-2">
            <p className="font-semibold">Paramètres de précommande (sur commande)</p>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Field label="Délai estimé (jours)"><input className="input" inputMode="numeric" value={String(form.leadTimeDays)} onChange={(e) => set('leadTimeDays', e.target.value.replace(/\D/g, ''))} /></Field>
              <Field label="Acompte (%)"><input className="input" inputMode="numeric" value={String(form.depositPercent)} onChange={(e) => set('depositPercent', e.target.value.replace(/\D/g, ''))} placeholder="Vide = réglage global" /></Field>
              <Field label="Qté max / commande"><input className="input" inputMode="numeric" value={String(form.maxPreorderQty)} onChange={(e) => set('maxPreorderQty', e.target.value.replace(/\D/g, ''))} /></Field>
              <div className="flex flex-col justify-end gap-2">
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!form.fullPaymentRequired} onChange={(e) => set('fullPaymentRequired', e.target.checked)} /> Paiement intégral requis</label>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!form.autoPreorderWhenOutOfStock} onChange={(e) => set('autoPreorderWhenOutOfStock', e.target.checked)} /> Bascule en précommande si rupture</label>
              </div>
            </div>
          </div>
        </div>
      )}

      {tab === 'Variantes' && (
        <div className="card space-y-3 p-5">
          {isNew ? <Alert tone="info">Enregistrez le produit pour gérer ses variantes. Une variante « Standard » est créée automatiquement.</Alert> : (
            <>
              <div className="flex justify-between">
                <p className="text-sm text-ink-soft">{variants.length} variante(s)</p>
                <div className="flex gap-2">
                  <button className="btn-secondary !min-h-[34px] text-xs" onClick={() => setGenerateOpen(true)}><Wand2 className="h-4 w-4" /> Générer</button>
                  <button className="btn-primary !min-h-[34px] text-xs" onClick={() => setVariantModal({ name: '', price: Number(form.basePrice) || 0, sku: '', isDefault: variants.length === 0, isActive: true, attributeValueIds: [] })}><Plus className="h-4 w-4" /> Ajouter</button>
                </div>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-sm">
                  <thead><tr className="border-b border-sand text-left text-xs uppercase text-ink-soft"><th className="th">Variante</th><th className="th">SKU</th><th className="th">Prix</th><th className="th">Stock (dispo)</th><th className="th">Seuil</th><th className="th">Dispo</th><th className="th" /></tr></thead>
                  <tbody className="divide-y divide-sand/60">
                    {variants.map((v) => (
                      <tr key={v.id}>
                        <td className="td"><p className="font-medium">{v.name}</p>{v.isDefault && <Badge tone="gold">Défaut</Badge>}{!v.isActive && <Badge>Inactif</Badge>}</td>
                        <td className="td font-mono text-xs">{v.sku}</td>
                        <td className="td">{formatFCFA(v.promoPrice ?? v.price)}{v.promoPrice ? <span className="ml-1 text-xs text-ink-soft line-through">{formatFCFA(v.price)}</span> : null}</td>
                        <td className="td"><span className={v.stockAvailable <= v.lowStockThreshold ? 'font-semibold text-danger' : ''}>{v.stockAvailable}</span> / {v.stockOnHand}</td>
                        <td className="td">{v.lowStockThreshold}</td>
                        <td className="td text-xs">{v.availabilityMode ? AVAILABILITY_LABELS[v.availabilityMode] : '—'}</td>
                        <td className="td whitespace-nowrap">
                          <button className="btn-ghost !min-h-[30px] !px-2 text-xs" onClick={() => setVariantModal({ ...v })}>Modifier</button>
                          {variants.length > 1 && <button className="btn-ghost !min-h-[30px] !px-2 text-xs text-danger" onClick={() => confirm(`Supprimer ${v.name} ?`) && removeVariant.mutate(v.id)}><Trash2 className="h-4 w-4" /></button>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      )}

      {tab === 'Images' && (
        <div className="card p-5">
          {isNew ? <Alert tone="info">Enregistrez le produit pour ajouter des images.</Alert> : (
            <>
              <div className="mb-3 flex items-center justify-between">
                <p className="text-sm text-ink-soft">Glissez vos images — converties en WebP (miniature, moyenne, grande).</p>
                <button className="btn-primary !min-h-[34px] text-xs" onClick={() => fileRef.current?.click()}><ImagePlus className="h-4 w-4" /> Ajouter</button>
                <input ref={fileRef} type="file" accept="image/*" multiple className="hidden" onChange={(e) => { [...(e.target.files ?? [])].forEach(uploadImage); e.target.value = ''; }} />
              </div>
              {images.length ? (
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                  {images.map((img) => (
                    <li key={img.id} className={`group relative overflow-hidden rounded-xl border ${img.isMain ? 'border-gold' : 'border-sand'}`}>
                      <img src={assetUrl(img.mediumUrl)} alt={img.alt} className="aspect-square w-full object-cover" />
                      <div className="absolute inset-x-0 bottom-0 flex items-center justify-between bg-ink/60 px-2 py-1 opacity-0 transition group-hover:opacity-100">
                        {!img.isMain ? (
                          <button className="text-xs text-gold-light" onClick={() => setMainImage(img.id)}><Star className="mr-0.5 inline h-3 w-3" /> Principale</button>
                        ) : <span className="text-xs text-gold-light"><Check className="mr-0.5 inline h-3 w-3" /> Principale</span>}
                        <button className="text-xs text-danger" onClick={() => removeImage.mutate(img.id)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                      </div>
                    </li>
                  ))}
                </ul>
              ) : <p className="py-8 text-center text-sm text-ink-soft">Aucune image pour l'instant.</p>}
            </>
          )}
        </div>
      )}

      {tab === 'SEO' && (
        <div className="card grid gap-4 p-5">
          <Field label="Titre SEO" hint={`${String(form.seoTitle).length}/190 caractères`}><input className="input" maxLength={190} value={String(form.seoTitle)} onChange={(e) => set('seoTitle', e.target.value)} /></Field>
          <Field label="Description SEO" hint={`${String(form.seoDescription).length}/320 caractères`}><textarea className="input" rows={3} maxLength={320} value={String(form.seoDescription)} onChange={(e) => set('seoDescription', e.target.value)} /></Field>
          <div className="rounded-lg bg-sand/40 p-4 text-sm">
            <p className="text-xs uppercase tracking-wide text-ink-soft">Aperçu Google</p>
            <p className="mt-1 font-medium text-[#1a0dab]">{String(form.seoTitle || form.name || 'Titre du produit')} — DutCh’M</p>
            <p className="text-xs text-success">amoria.ga/produit/{String(form.slug || '…')}</p>
            <p className="text-xs text-ink-soft">{String(form.seoDescription || form.shortDescription || 'Description du produit…')}</p>
          </div>
        </div>
      )}

      <Modal open={!!variantModal} onClose={() => setVariantModal(null)} title={variantModal?.id ? 'Modifier la variante' : 'Nouvelle variante'} wide>
        {variantModal && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Nom"><input className="input" value={variantModal.name ?? ''} onChange={(e) => setVariantModal({ ...variantModal, name: e.target.value })} placeholder="Ex. Blanc cassé — Grand" /></Field>
            <Field label="SKU"><input className="input font-mono text-xs" value={variantModal.sku ?? ''} onChange={(e) => setVariantModal({ ...variantModal, sku: e.target.value.toUpperCase() })} placeholder="auto" /></Field>
            <Field label="Prix (FCFA)"><input className="input" inputMode="numeric" value={variantModal.price ?? ''} onChange={(e) => setVariantModal({ ...variantModal, price: Number(e.target.value.replace(/\D/g, '')) })} disabled={!canPrices && !!variantModal.id} /></Field>
            <Field label="Prix barré"><input className="input" inputMode="numeric" value={variantModal.compareAtPrice ?? ''} onChange={(e) => setVariantModal({ ...variantModal, compareAtPrice: numOrNull(e.target.value.replace(/\D/g, '')) })} disabled={!canPrices && !!variantModal.id} /></Field>
            <Field label="Prix promo"><input className="input" inputMode="numeric" value={variantModal.promoPrice ?? ''} onChange={(e) => setVariantModal({ ...variantModal, promoPrice: numOrNull(e.target.value.replace(/\D/g, '')) })} disabled={!canPrices && !!variantModal.id} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Promo début"><input className="input" type="datetime-local" value={variantModal.promoStartsAt?.slice(0, 16) ?? ''} onChange={(e) => setVariantModal({ ...variantModal, promoStartsAt: e.target.value || null })} /></Field>
              <Field label="Promo fin"><input className="input" type="datetime-local" value={variantModal.promoEndsAt?.slice(0, 16) ?? ''} onChange={(e) => setVariantModal({ ...variantModal, promoEndsAt: e.target.value || null })} /></Field>
            </div>
            <Field label="Seuil d'alerte stock"><input className="input" inputMode="numeric" value={variantModal.lowStockThreshold ?? 3} onChange={(e) => setVariantModal({ ...variantModal, lowStockThreshold: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
            <Field label="Disponibilité forcée"><select className="input" value={variantModal.availabilityMode ?? ''} onChange={(e) => setVariantModal({ ...variantModal, availabilityMode: (e.target.value || null) as AvailabilityMode | null })}>
              <option value="">Héritée du produit</option>
              {Object.entries(AVAILABILITY_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select></Field>
            {!variantModal.id && <Field label="Stock initial"><input className="input" inputMode="numeric" value={variantModal.stockOnHand ?? 0} onChange={(e) => setVariantModal({ ...variantModal, stockOnHand: Number(e.target.value.replace(/\D/g, '')) })} /></Field>}
            {!!attributes?.length && (
              <Field label="Attributs">
                <div className="max-h-36 space-y-1 overflow-y-auto rounded-lg border border-sand p-2">
                  {attributes.map((a) => (
                    <div key={a.id}>
                      <p className="text-xs font-semibold uppercase text-ink-soft">{a.name}</p>
                      <div className="flex flex-wrap gap-1">
                        {a.values.map((v) => {
                          const on = (variantModal.attributeValueIds ?? []).includes(v.id);
                          return (
                            <button key={v.id} type="button" onClick={() => {
                              const cur = new Set(variantModal.attributeValueIds ?? []);
                              a.values.forEach((o) => cur.delete(o.id));
                              if (!on) cur.add(v.id);
                              setVariantModal({ ...variantModal, attributeValueIds: [...cur] });
                            }} className={`badge ${on ? 'bg-gold/25 text-gold-dark' : 'bg-sand text-ink-soft'}`}>{v.hexColor && <span className="mr-1 inline-block h-2.5 w-2.5 rounded-full" style={{ background: v.hexColor }} />}{v.value}</button>
                          );
                        })}
                      </div>
                    </div>
                  ))}
                </div>
              </Field>
            )}
            <div className="flex items-center gap-4 sm:col-span-2">
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!variantModal.isDefault} onChange={(e) => setVariantModal({ ...variantModal, isDefault: e.target.checked })} /> Variante par défaut</label>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={variantModal.isActive !== false} onChange={(e) => setVariantModal({ ...variantModal, isActive: e.target.checked })} /> Active</label>
            </div>
            <button className="btn-primary sm:col-span-2" disabled={saveVariant.isPending || !variantModal.name || variantModal.price === undefined} onClick={() => saveVariant.mutate({
              ...variantModal,
              price: Number(variantModal.price),
              compareAtPrice: variantModal.compareAtPrice ?? null,
              promoPrice: variantModal.promoPrice ?? null,
              promoStartsAt: variantModal.promoStartsAt || null,
              promoEndsAt: variantModal.promoEndsAt || null,
              initialStock: variantModal.id ? undefined : variantModal.stockOnHand ?? 0,
              stockOnHand: undefined,
            } as Partial<VariantRow>)}>Enregistrer</button>
          </div>
        )}
      </Modal>

      <Modal open={generateOpen} onClose={() => setGenerateOpen(false)} title="Générer des variantes">
        <p className="mb-3 text-sm text-ink-soft">Sélectionnez les valeurs d'attributs : une variante est créée pour chaque combinaison manquante.</p>
        <div className="max-h-64 space-y-2 overflow-y-auto">
          {attributes?.map((a) => (
            <div key={a.id}>
              <p className="text-xs font-semibold uppercase text-ink-soft">{a.name}</p>
              <div className="flex flex-wrap gap-1">
                {a.values.map((v) => {
                  const on = genChecked.has(v.id);
                  return (
                    <button key={v.id} type="button" onClick={() => setGenChecked((s) => { const n = new Set(s); if (n.has(v.id)) n.delete(v.id); else n.add(v.id); return n; })} className={`badge ${on ? 'bg-gold/25 text-gold-dark' : 'bg-sand text-ink-soft'}`}>{v.value}</button>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
        <button className="btn-primary mt-4 w-full" disabled={generate.isPending} onClick={() => { generate.mutate({ attributeValueIds: [...genChecked] }); setGenChecked(new Set()); }}>Générer les combinaisons</button>
      </Modal>
    </div>
  );
}
