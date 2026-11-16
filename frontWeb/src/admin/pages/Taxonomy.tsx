import { AttributeType, formatDate, Permission, slugify } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert, Badge, DataTable, Field, Modal, PageLoader } from '@/admin/components/ui';
import { api, assetUrl, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';

interface CategoryRow { id: number; name: string; slug: string; code: string; parentId: number | null; productCount?: number; sortOrder: number; isActive: boolean; description: string | null; imageUrl: string | null; seoTitle: string | null; seoDescription: string | null }
interface CollectionRow { id: number; name: string; slug: string; isActive: boolean; isFeatured: boolean; startsAt: string | null; endsAt: string | null; sortOrder: number; productCount: number; description: string | null }
interface SupplierRow { id: number; name: string; country: string; contactName: string | null; phone: string | null; wechat: string | null; email: string | null; usualLeadTimeDays: number; notes: string | null; isActive: boolean; productCount?: number }
interface AttributeValueRow { id: number; attributeId: number; value: string; hexColor: string | null }
interface AttributeRow { id: number; name: string; code: string; type: AttributeType; sortOrder: number; values: AttributeValueRow[] }

const TABS = ['Catégories', 'Collections', 'Fournisseurs', 'Attributs'] as const;
const num = (v: string) => (v === '' ? undefined : Number(v));

export default function Taxonomy() {
  const user = useAuthStore((s) => s.user);
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [tab, setTab] = useState<(typeof TABS)[number]>('Catégories');
  const [edit, setEdit] = useState<{ kind: string; data: Record<string, unknown>; id?: number } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const canWrite = hasPermission(user, Permission.CATALOG_WRITE);
  const canSuppliers = hasPermission(user, Permission.SUPPLIERS_WRITE);

  const { data: categories, isLoading: l1 } = useQuery({ queryKey: ['categories-admin'], queryFn: () => api<CategoryRow[]>('/admin/categories') });
  const { data: collections, isLoading: l2 } = useQuery({ queryKey: ['collections-admin'], queryFn: () => api<CollectionRow[]>('/admin/collections') });
  const { data: suppliers, isLoading: l3 } = useQuery({ queryKey: ['suppliers-admin'], queryFn: () => api<SupplierRow[]>('/admin/suppliers') });
  const { data: attributes, isLoading: l4 } = useQuery({ queryKey: ['attributes-admin'], queryFn: () => api<AttributeRow[]>('/admin/attributes') });

  const invalidate = () => ['categories-admin', 'collections-admin', 'suppliers-admin', 'attributes-admin'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
  const save = useMutation({
    mutationFn: ({ path, method, body }: { path: string; method: string; body: unknown }) => api(path, { method, body }),
    onSuccess: () => { notify('Enregistré'); setEdit(null); invalidate(); },
    onError: (e) => setError(errorMessage(e)),
  });
  const del = (path: string, label: string) => confirm(`Supprimer ${label} ?`) && api(path, { method: 'DELETE' }).then(() => { notify('Supprimé'); invalidate(); }).catch((e) => notify(errorMessage(e), 'error'));

  const openEdit = (kind: string, data: Record<string, unknown>, id?: number) => { setEdit({ kind, data, id }); setError(null); };
  const submit = () => {
    if (!edit) return;
    const { kind, data, id } = edit;
    const clean = Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? (['parentId', 'imageUrl', 'description', 'seoTitle', 'seoDescription', 'contactName', 'phone', 'wechat', 'email', 'notes', 'startsAt', 'endsAt', 'hexColor'].includes(k) ? null : v) : v]));
    const method = id ? 'PATCH' : 'POST';
    if (kind === 'value') {
      const { attributeId, ...rest } = clean;
      return save.mutate({ path: id ? `/admin/attribute-values/${id}` : `/admin/attributes/${attributeId}/values`, method, body: rest });
    }
    const base = { category: 'categories', collection: 'collections', supplier: 'suppliers', attribute: 'attributes' }[kind] ?? kind;
    save.mutate({ path: `/admin/${base}${id ? `/${id}` : ''}`, method, body: clean });
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Link to="/admin/catalogue" className="btn-ghost !px-2"><ArrowLeft className="h-4 w-4" /></Link>
        <h1 className="text-2xl">Organisation du catalogue</h1>
      </div>
      <div className="flex gap-1 overflow-x-auto rounded-lg bg-sand/50 p-1" role="tablist">
        {TABS.map((t) => <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)} className={`whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold ${tab === t ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}>{t}</button>)}
      </div>

      {tab === 'Catégories' && (
        <section className="space-y-3">
          {canWrite && <button className="btn-primary !min-h-[34px] text-xs" onClick={() => openEdit('category', { name: '', slug: '', code: '', parentId: '', sortOrder: 0, isActive: true, description: '', imageUrl: '', seoTitle: '', seoDescription: '' })}><Plus className="h-4 w-4" /> Catégorie</button>}
          {l1 ? <PageLoader /> : (
            <DataTable head={['Nom', 'Code', 'Slug', 'Produits', 'Active', 'Ordre', '']}>
              {categories?.map((c) => (
                <tr key={c.id}>
                  <td className="td font-medium">{c.parentId ? '— ' : ''}{c.name}</td>
                  <td className="td font-mono text-xs">{c.code}</td>
                  <td className="td font-mono text-xs text-ink-soft">{c.slug}</td>
                  <td className="td">{c.productCount ?? '—'}</td>
                  <td className="td"><Badge tone={c.isActive ? 'success' : 'muted'}>{c.isActive ? 'Oui' : 'Non'}</Badge></td>
                  <td className="td">{c.sortOrder}</td>
                  {canWrite && (
                    <td className="td whitespace-nowrap">
                      <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => openEdit('category', { name: c.name, slug: c.slug, code: c.code, parentId: c.parentId ?? '', sortOrder: c.sortOrder, isActive: c.isActive, description: c.description ?? '', imageUrl: c.imageUrl ?? '', seoTitle: c.seoTitle ?? '', seoDescription: c.seoDescription ?? '' }, c.id)} aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                      <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => del(`/admin/categories/${c.id}`, c.name)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                    </td>
                  )}
                </tr>
              ))}
            </DataTable>
          )}
        </section>
      )}

      {tab === 'Collections' && (
        <section className="space-y-3">
          {canWrite && <button className="btn-primary !min-h-[34px] text-xs" onClick={() => openEdit('collection', { name: '', slug: '', description: '', imageUrl: '', startsAt: '', endsAt: '', isActive: true, isFeatured: false, sortOrder: 0 })}><Plus className="h-4 w-4" /> Collection</button>}
          {l2 ? <PageLoader /> : (
            <DataTable head={['Nom', 'Slug', 'Produits', 'Fenêtre', 'En une', 'Active', '']}>
              {collections?.map((c) => (
                <tr key={c.id}>
                  <td className="td font-medium">{c.name}</td>
                  <td className="td font-mono text-xs text-ink-soft">{c.slug}</td>
                  <td className="td">{c.productCount}</td>
                  <td className="td text-xs">{c.startsAt ? formatDate(c.startsAt) : '—'} → {c.endsAt ? formatDate(c.endsAt) : '∞'}</td>
                  <td className="td">{c.isFeatured && <Badge tone="gold">Oui</Badge>}</td>
                  <td className="td"><Badge tone={c.isActive ? 'success' : 'muted'}>{c.isActive ? 'Oui' : 'Non'}</Badge></td>
                  {canWrite && (
                    <td className="td whitespace-nowrap">
                      <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => openEdit('collection', { name: c.name, slug: c.slug, description: c.description ?? '', imageUrl: '', startsAt: c.startsAt?.slice(0, 16) ?? '', endsAt: c.endsAt?.slice(0, 16) ?? '', isActive: c.isActive, isFeatured: c.isFeatured, sortOrder: c.sortOrder }, c.id)} aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                      <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => del(`/admin/collections/${c.id}`, c.name)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                    </td>
                  )}
                </tr>
              ))}
            </DataTable>
          )}
        </section>
      )}

      {tab === 'Fournisseurs' && (
        <section className="space-y-3">
          {canSuppliers && <button className="btn-primary !min-h-[34px] text-xs" onClick={() => openEdit('supplier', { name: '', country: 'Chine', contactName: '', phone: '', wechat: '', email: '', usualLeadTimeDays: 14, notes: '', isActive: true })}><Plus className="h-4 w-4" /> Fournisseur</button>}
          {l3 ? <PageLoader /> : (
            <DataTable head={['Nom', 'Pays', 'Contact', 'Délai habituel', 'Produits', 'Active', '']}>
              {suppliers?.map((s) => (
                <tr key={s.id}>
                  <td className="td font-medium">{s.name}</td>
                  <td className="td">{s.country}</td>
                  <td className="td text-xs">{[s.contactName, s.phone, s.wechat && `WeChat: ${s.wechat}`, s.email].filter(Boolean).join(' · ') || '—'}</td>
                  <td className="td">~{s.usualLeadTimeDays} jours</td>
                  <td className="td">{s.productCount ?? '—'}</td>
                  <td className="td"><Badge tone={s.isActive ? 'success' : 'muted'}>{s.isActive ? 'Oui' : 'Non'}</Badge></td>
                  {canSuppliers && (
                    <td className="td whitespace-nowrap">
                      <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => openEdit('supplier', { name: s.name, country: s.country, contactName: s.contactName ?? '', phone: s.phone ?? '', wechat: s.wechat ?? '', email: s.email ?? '', usualLeadTimeDays: s.usualLeadTimeDays, notes: s.notes ?? '', isActive: s.isActive }, s.id)} aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                      <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => del(`/admin/suppliers/${s.id}`, s.name)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                    </td>
                  )}
                </tr>
              ))}
            </DataTable>
          )}
        </section>
      )}

      {tab === 'Attributs' && (
        <section className="space-y-3">
          {canWrite && <button className="btn-primary !min-h-[34px] text-xs" onClick={() => openEdit('attribute', { name: '', code: '', type: AttributeType.OTHER, sortOrder: 0 })}><Plus className="h-4 w-4" /> Attribut</button>}
          {l4 ? <PageLoader /> : (
            <div className="grid gap-3 md:grid-cols-2">
              {attributes?.map((a) => (
                <div key={a.id} className="card p-4">
                  <div className="mb-2 flex items-center justify-between">
                    <p className="font-semibold">{a.name} <span className="text-xs font-normal text-ink-soft">({a.code} · {a.type})</span></p>
                    {canWrite && (
                      <div>
                        <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => openEdit('attribute', { name: a.name, code: a.code, type: a.type, sortOrder: a.sortOrder }, a.id)} aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                        <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => del(`/admin/attributes/${a.id}`, a.name)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                      </div>
                    )}
                  </div>
                  <div className="flex flex-wrap gap-1">
                    {a.values.map((v) => (
                      <span key={v.id} className="badge bg-sand text-ink">
                        {v.hexColor && <span className="mr-1 inline-block h-2.5 w-2.5 rounded-full" style={{ background: v.hexColor }} />}
                        {v.value}
                        {canWrite && <button type="button" onClick={() => del(`/admin/attribute-values/${v.id}`, v.value)} aria-label={`Supprimer ${v.value}`}><Trash2 className="ml-1 h-3 w-3 text-danger" /></button>}
                      </span>
                    ))}
                    {canWrite && <button className="badge bg-gold/20 text-gold-dark" onClick={() => openEdit('value', { attributeId: a.id, value: '', hexColor: '', sortOrder: 0 })}>+ valeur</button>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </section>
      )}

      <Modal open={!!edit} onClose={() => setEdit(null)} title="Enregistrer">
        {edit && (
          <div className="space-y-3">
            {Object.entries(edit.data).map(([key, value]) => {
              if (key === 'isActive' || key === 'isFeatured') {
                return <label key={key} className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!value} onChange={(e) => setEdit({ ...edit, data: { ...edit.data, [key]: e.target.checked } })} /> {key === 'isActive' ? 'Actif(ve)' : 'À la une'}</label>;
              }
              if (key === 'type') {
                return (
                  <Field key={key} label="Type"><select className="input" value={String(value)} onChange={(e) => setEdit({ ...edit, data: { ...edit.data, [key]: e.target.value } })}>
                    {Object.values(AttributeType).map((t) => <option key={t} value={t}>{t}</option>)}
                  </select></Field>
                );
              }
              if (key === 'parentId') {
                return (
                  <Field key={key} label="Catégorie parente"><select className="input" value={String(value)} onChange={(e) => setEdit({ ...edit, data: { ...edit.data, [key]: e.target.value } })}>
                    <option value="">—</option>
                    {categories?.filter((c) => c.id !== edit.id).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                  </select></Field>
                );
              }
              if (key === 'description' || key === 'notes' || key === 'seoDescription') {
                return <Field key={key} label={{ description: 'Description', notes: 'Notes', seoDescription: 'Description SEO' }[key]}><textarea className="input" rows={3} value={String(value)} onChange={(e) => setEdit({ ...edit, data: { ...edit.data, [key]: e.target.value } })} /></Field>;
              }
              const labels: Record<string, string> = {
                name: 'Nom', slug: 'Slug', code: 'Code', sortOrder: 'Ordre', imageUrl: 'Image (URL /uploads)', seoTitle: 'Titre SEO',
                startsAt: 'Début', endsAt: 'Fin', country: 'Pays', contactName: 'Contact', phone: 'Téléphone', wechat: 'WeChat',
                email: 'E-mail', usualLeadTimeDays: 'Délai habituel (jours)', value: 'Valeur', hexColor: 'Couleur #RRGGBB',
              };
              const numeric = ['sortOrder', 'usualLeadTimeDays'].includes(key);
              const dates = ['startsAt', 'endsAt'].includes(key);
              return (
                <Field key={key} label={labels[key] ?? key}>
                  <input
                    className="input"
                    type={dates ? 'datetime-local' : 'text'}
                    inputMode={numeric ? 'numeric' : undefined}
                    value={String(value ?? '')}
                    onChange={(e) => setEdit({ ...edit, data: { ...edit.data, [key]: key === 'slug' ? slugify(e.target.value) : numeric ? num(e.target.value.replace(/\D/g, '')) ?? 0 : e.target.value } })}
                  />
                </Field>
              );
            })}
            {edit.data.imageUrl !== undefined && String(edit.data.imageUrl) && <img src={assetUrl(String(edit.data.imageUrl))} alt="" className="h-20 rounded object-cover" />}
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary w-full" disabled={save.isPending} onClick={submit}>Enregistrer</button>
          </div>
        )}
      </Modal>
    </div>
  );
}
