import {
  formatDate,
  NotificationChannelType,
  NotificationTemplateCode,
  Paginated,
  ReviewStatus,
} from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Eye, Pencil, Plus, Star, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Alert, Badge, DataTable, EmptyState, Field, Modal, PageLoader, Pagination } from '@/admin/components/ui';
import { api, assetUrl, errorMessage } from '@/admin/lib/api';
import { useUiStore } from '@/admin/store/ui';

interface PageRow { id: number; slug: string; title: string; isPublished: boolean; updatedAt: string }
interface FaqCategoryRow { id: number; name: string; sortOrder: number; faqs: { id: number; question: string; answer: string; sortOrder: number; isActive: boolean }[] }
interface FaqRow { id: number; categoryId: number; question: string; answer: string; sortOrder: number; isActive: boolean }
interface BannerRow { id: number; title: string; subtitle: string | null; imageDesktopUrl: string; imageMobileUrl: string | null; linkUrl: string | null; ctaLabel: string | null; startsAt: string | null; endsAt: string | null; isActive: boolean; sortOrder: number }
interface ReviewRow { id: number; rating: number; comment: string | null; status: ReviewStatus; createdAt: string; product: { id: number; name: string }; customer: { id: number; fullName: string } | null }
interface TemplateRow { id?: number; code: NotificationTemplateCode; channel: NotificationChannelType; name: string; subject: string | null; body: string; isActive: boolean }

const TABS = ['Pages', 'FAQ', 'Bannières', 'Avis', 'Gabarits'] as const;
const TEMPLATE_NAMES: Record<NotificationTemplateCode, string> = {
  ORDER_CREATED: 'Commande créée', PAYMENT_CONFIRMED: 'Paiement confirmé', PAYMENT_FAILED: 'Paiement échoué',
  SHIPMENT_STEP: 'Étape d\'expédition', SHIPMENT_DELAYED: 'Retard d\'expédition', OUT_FOR_DELIVERY: 'En cours de livraison',
  ORDER_DELIVERED: 'Commande livrée', BACK_IN_STOCK: 'De retour en stock', CONVERSATION_REPLY: 'Réponse à un message',
  PASSWORD_RESET: 'Mot de passe oublié', PHONE_VERIFICATION: 'Vérification du téléphone',
};
const REVIEW_LABELS: Record<ReviewStatus, string> = { PENDING: 'En attente', APPROVED: 'Approuvé', REJECTED: 'Refusé' };

export default function Content() {
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'Pages';
  const page = Number(params.get('page') ?? 1);
  const status = params.get('status') ?? '';
  const setParam = (k: string, v: string) => {
    const n = new URLSearchParams(params);
    if (v) n.set(k, v); else n.delete(k);
    if (k !== 'page') n.delete('page');
    setParams(n, { replace: true });
  };

  const [pageEdit, setPageEdit] = useState<Partial<PageRow & { content: string; seoTitle: string; seoDescription: string }> | null>(null);
  const [catEdit, setCatEdit] = useState<Partial<FaqCategoryRow> | null>(null);
  const [faqEdit, setFaqEdit] = useState<Partial<FaqRow> | null>(null);
  const [bannerEdit, setBannerEdit] = useState<Partial<BannerRow> | null>(null);
  const [tplEdit, setTplEdit] = useState<TemplateRow | null>(null);
  const [tplPreview, setTplPreview] = useState<{ subject: string; body: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const bannerFile = useRef<HTMLInputElement>(null);

  const pages = useQuery({ queryKey: ['admin-pages'], queryFn: () => api<PageRow[]>('/admin/pages'), enabled: tab === 'Pages' });
  const faqs = useQuery({ queryKey: ['admin-faq'], queryFn: () => api<FaqCategoryRow[]>('/admin/faq'), enabled: tab === 'FAQ' });
  const banners = useQuery({ queryKey: ['admin-banners'], queryFn: () => api<BannerRow[]>('/admin/banners'), enabled: tab === 'Bannières' });
  const reviews = useQuery({ queryKey: ['admin-reviews', status, page], queryFn: () => api<Paginated<ReviewRow>>('/admin/reviews', { query: { status: status || undefined, page, limit: 20 } }), enabled: tab === 'Avis', placeholderData: (p) => p });
  const templates = useQuery({ queryKey: ['notif-templates'], queryFn: () => api<TemplateRow[]>('/admin/notification-templates'), enabled: tab === 'Gabarits' });

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['admin-pages', 'admin-faq', 'admin-banners', 'admin-reviews', 'notif-templates'] });
  };
  const onErr = (e: unknown) => setError(errorMessage(e));

  const savePage = useMutation({ mutationFn: (p: Partial<PageRow>) => (p.id ? api(`/admin/pages/${p.id}`, { method: 'PATCH', body: p }) : api('/admin/pages', { method: 'POST', body: p })), onSuccess: () => { notify('Page enregistrée'); setPageEdit(null); refresh(); }, onError: onErr });
  const saveCat = useMutation({ mutationFn: (c: Partial<FaqCategoryRow>) => (c.id ? api(`/admin/faq-categories/${c.id}`, { method: 'PUT', body: c }) : api('/admin/faq-categories', { method: 'POST', body: c })), onSuccess: () => { notify('Catégorie enregistrée'); setCatEdit(null); refresh(); }, onError: onErr });
  const saveFaq = useMutation({ mutationFn: (f: Partial<FaqRow>) => (f.id ? api(`/admin/faqs/${f.id}`, { method: 'PATCH', body: f }) : api('/admin/faqs', { method: 'POST', body: f })), onSuccess: () => { notify('Question enregistrée'); setFaqEdit(null); refresh(); }, onError: onErr });
  const saveBanner = useMutation({ mutationFn: (b: Partial<BannerRow>) => (b.id ? api(`/admin/banners/${b.id}`, { method: 'PATCH', body: b }) : api('/admin/banners', { method: 'POST', body: b })), onSuccess: () => { notify('Bannière enregistrée'); setBannerEdit(null); refresh(); }, onError: onErr });
  const moderate = useMutation({ mutationFn: ({ id, status: s }: { id: number; status: ReviewStatus }) => api(`/admin/reviews/${id}/moderate`, { method: 'POST', body: { status: s } }), onSuccess: () => { notify('Avis modéré'); refresh(); }, onError: (e) => notify(errorMessage(e), 'error') });
  const saveTpl = useMutation({ mutationFn: (t: TemplateRow) => api(`/admin/notification-templates/${t.code}/${t.channel}`, { method: 'PUT', body: { subject: t.subject, body: t.body, isActive: t.isActive } }), onSuccess: () => { notify('Gabarit enregistré'); setTplEdit(null); refresh(); }, onError: onErr });
  const previewTpl = useMutation({ mutationFn: (t: TemplateRow) => api<{ subject: string; body: string }>(`/admin/notification-templates/${t.code}/${t.channel}/preview`, { method: 'POST', body: { subject: t.subject, body: t.body } }), onSuccess: (d) => setTplPreview(d), onError: (e) => notify(errorMessage(e), 'error') });

  const uploadBannerImage = async (file: File) => {
    const fd = new FormData();
    fd.append('file', file);
    try {
      const up = await api<{ url: string }>('/admin/uploads', { method: 'POST', body: fd });
      setBannerEdit((b) => (b ? { ...b, imageDesktopUrl: up.url } : b));
    } catch (e) {
      notify(errorMessage(e), 'error');
    }
  };

  const rm = (path: string, label: string) => { if (confirm(`Supprimer « ${label} » ?`)) api(path, { method: 'DELETE' }).then(refresh).catch((e) => notify(errorMessage(e), 'error')); };

  return (
    <div className="space-y-4">
      <h1 className="text-2xl">Contenus</h1>
      <div className="flex gap-1 overflow-x-auto rounded-lg bg-sand/50 p-1" role="tablist">
        {TABS.map((t) => <button key={t} role="tab" aria-selected={tab === t} onClick={() => setParam('tab', t)} className={`whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold ${tab === t ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}>{t}</button>)}
      </div>

      {tab === 'Pages' && (
        <div className="space-y-3">
          <button className="btn-primary" onClick={() => { setPageEdit({ slug: '', title: '', content: '', isPublished: true }); setError(null); }}><Plus className="h-4 w-4" /> Page</button>
          {pages.isLoading ? <PageLoader /> : (
            <DataTable head={['Titre', 'Slug', 'Publié', 'Mise à jour', '']}>
              {pages.data?.map((p) => (
                <tr key={p.id}>
                  <td className="td font-medium">{p.title}</td>
                  <td className="td font-mono text-xs">/{p.slug}</td>
                  <td className="td"><Badge tone={p.isPublished ? 'success' : 'muted'}>{p.isPublished ? 'Oui' : 'Non'}</Badge></td>
                  <td className="td text-xs">{formatDate(p.updatedAt)}</td>
                  <td className="td whitespace-nowrap">
                    <button className="btn-ghost !min-h-[30px] !px-2" onClick={async () => { const full = await api<PageRow & { content: string }>(`/admin/pages/${p.id}`); setPageEdit(full); setError(null); }} aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                    <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => rm(`/admin/pages/${p.id}`, p.title)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                  </td>
                </tr>
              ))}
            </DataTable>
          )}
        </div>
      )}

      {tab === 'FAQ' && (
        <div className="space-y-3">
          <button className="btn-secondary" onClick={() => { setCatEdit({ name: '', sortOrder: 0 }); setError(null); }}><Plus className="h-4 w-4" /> Catégorie</button>
          {faqs.isLoading ? <PageLoader /> : faqs.data?.map((c) => (
            <div key={c.id} className="card p-4">
              <div className="mb-2 flex items-center justify-between">
                <h2 className="font-semibold">{c.name}</h2>
                <div className="flex gap-1">
                  <button className="btn-ghost !min-h-[30px] !px-2 text-xs" onClick={() => { setFaqEdit({ categoryId: c.id, question: '', answer: '', sortOrder: 0, isActive: true }); setError(null); }}><Plus className="h-4 w-4" /> Question</button>
                  <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => { setCatEdit(c); setError(null); }} aria-label="Modifier la catégorie"><Pencil className="h-4 w-4" /></button>
                  <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => rm(`/admin/faq-categories/${c.id}`, c.name)} aria-label="Supprimer la catégorie"><Trash2 className="h-4 w-4" /></button>
                </div>
              </div>
              <ul className="divide-y divide-sand/50">
                {(c.faqs ?? []).map((f) => (
                  <li key={f.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                    <span className={f.isActive ? '' : 'text-ink-soft line-through'}>{f.question}</span>
                    <span className="flex shrink-0 gap-1">
                      <button className="btn-ghost !min-h-[28px] !px-2" onClick={() => { setFaqEdit(f); setError(null); }} aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                      <button className="btn-ghost !min-h-[28px] !px-2 text-danger" onClick={() => rm(`/admin/faqs/${f.id}`, f.question)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}

      {tab === 'Bannières' && (
        <div className="space-y-3">
          <button className="btn-primary" onClick={() => { setBannerEdit({ title: '', imageDesktopUrl: '', isActive: true, sortOrder: 0 }); setError(null); }}><Plus className="h-4 w-4" /> Bannière</button>
          {banners.isLoading ? <PageLoader /> : (
            <DataTable head={['Aperçu', 'Titre', 'Lien', 'Validité', 'Ordre', 'Actif', '']}>
              {banners.data?.map((b) => (
                <tr key={b.id}>
                  <td className="td"><img src={assetUrl(b.imageDesktopUrl)} alt="" className="h-10 w-20 rounded object-cover" /></td>
                  <td className="td">{b.title}<p className="text-xs text-ink-soft">{b.subtitle ?? ''}</p></td>
                  <td className="td font-mono text-xs">{b.linkUrl ?? '—'}</td>
                  <td className="td text-xs">{b.startsAt ? formatDate(b.startsAt) : '—'} → {b.endsAt ? formatDate(b.endsAt) : '∞'}</td>
                  <td className="td">{b.sortOrder}</td>
                  <td className="td"><Badge tone={b.isActive ? 'success' : 'muted'}>{b.isActive ? 'Oui' : 'Non'}</Badge></td>
                  <td className="td whitespace-nowrap">
                    <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => { setBannerEdit({ ...b, startsAt: b.startsAt?.slice(0, 16) ?? '', endsAt: b.endsAt?.slice(0, 16) ?? '' }); setError(null); }} aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                    <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => rm(`/admin/banners/${b.id}`, b.title)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                  </td>
                </tr>
              ))}
            </DataTable>
          )}
        </div>
      )}

      {tab === 'Avis' && (
        <div className="space-y-3">
          <select className="input max-w-[200px]" value={status} onChange={(e) => setParam('status', e.target.value)}>
            <option value="">Tous les statuts</option>
            {Object.entries(REVIEW_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
          {reviews.isLoading ? <PageLoader /> : !reviews.data?.data.length ? (
            <EmptyState title="Aucun avis" text="Les avis clients apparaîtront ici." />
          ) : (
            <>
              <DataTable head={['Produit', 'Client', 'Note', 'Commentaire', 'Date', 'Statut', '']}>
                {reviews.data.data.map((r) => (
                  <tr key={r.id}>
                    <td className="td text-sm">{r.product.name}</td>
                    <td className="td text-xs">{r.customer?.fullName ?? '—'}</td>
                    <td className="td"><span className="flex items-center gap-1 text-gold-dark"><Star className="h-4 w-4 fill-current" />{r.rating}</span></td>
                    <td className="td max-w-[300px] truncate text-xs">{r.comment ?? '—'}</td>
                    <td className="td text-xs">{formatDate(r.createdAt)}</td>
                    <td className="td"><Badge tone={r.status === 'APPROVED' ? 'success' : r.status === 'PENDING' ? 'warning' : 'danger'}>{REVIEW_LABELS[r.status]}</Badge></td>
                    <td className="td whitespace-nowrap">
                      {r.status !== 'APPROVED' && <button className="btn-ghost !min-h-[30px] !px-2 text-xs text-success" onClick={() => moderate.mutate({ id: r.id, status: ReviewStatus.APPROVED })}><Check className="h-4 w-4" /> Approuver</button>}
                      {r.status !== 'REJECTED' && <button className="btn-ghost !min-h-[30px] !px-2 text-xs text-danger" onClick={() => moderate.mutate({ id: r.id, status: ReviewStatus.REJECTED })}><X className="h-4 w-4" /> Refuser</button>}
                    </td>
                  </tr>
                ))}
              </DataTable>
              <Pagination page={page} limit={20} total={reviews.data.meta.total} onPage={(p) => setParam('page', String(p))} />
            </>
          )}
        </div>
      )}

      {tab === 'Gabarits' && (
        templates.isLoading ? <PageLoader /> : (
          <DataTable head={['Gabarit', 'Canal', 'Sujet', 'Actif', '']}>
            {templates.data?.map((t) => (
              <tr key={`${t.code}-${t.channel}`}>
                <td className="td"><span className="font-medium">{t.name || TEMPLATE_NAMES[t.code]}</span><p className="font-mono text-[10px] text-ink-soft">{t.code}</p></td>
                <td className="td"><Badge tone="muted">{t.channel}</Badge></td>
                <td className="td max-w-[250px] truncate text-xs">{t.subject ?? '—'}</td>
                <td className="td"><Badge tone={t.isActive ? 'success' : 'muted'}>{t.isActive ? 'Oui' : 'Non'}</Badge></td>
                <td className="td"><button className="btn-ghost !min-h-[30px] !px-2" onClick={() => { setTplEdit({ ...t }); setTplPreview(null); setError(null); }} aria-label="Modifier"><Pencil className="h-4 w-4" /></button></td>
              </tr>
            ))}
          </DataTable>
        )
      )}

      {/* --- Modales --- */}
      <Modal open={!!pageEdit} onClose={() => setPageEdit(null)} title={pageEdit?.id ? 'Modifier la page' : 'Nouvelle page'} wide>
        {pageEdit && (
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Titre"><input className="input" value={pageEdit.title ?? ''} onChange={(e) => setPageEdit({ ...pageEdit, title: e.target.value })} /></Field>
              <Field label="Slug"><input className="input font-mono" value={pageEdit.slug ?? ''} onChange={(e) => setPageEdit({ ...pageEdit, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-') })} /></Field>
            </div>
            <Field label="Contenu (HTML accepté)"><textarea className="input font-mono text-xs" rows={12} value={pageEdit.content ?? ''} onChange={(e) => setPageEdit({ ...pageEdit, content: e.target.value })} /></Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="SEO – titre"><input className="input" value={pageEdit.seoTitle ?? ''} onChange={(e) => setPageEdit({ ...pageEdit, seoTitle: e.target.value })} /></Field>
              <Field label="SEO – description"><input className="input" value={pageEdit.seoDescription ?? ''} onChange={(e) => setPageEdit({ ...pageEdit, seoDescription: e.target.value })} /></Field>
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={pageEdit.isPublished !== false} onChange={(e) => setPageEdit({ ...pageEdit, isPublished: e.target.checked })} /> Publiée</label>
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary w-full" disabled={savePage.isPending || !pageEdit.title || !pageEdit.slug} onClick={() => savePage.mutate(pageEdit)}>Enregistrer</button>
          </div>
        )}
      </Modal>

      <Modal open={!!catEdit} onClose={() => setCatEdit(null)} title="Catégorie FAQ">
        {catEdit && (
          <div className="space-y-3">
            <Field label="Nom"><input className="input" value={catEdit.name ?? ''} onChange={(e) => setCatEdit({ ...catEdit, name: e.target.value })} /></Field>
            <Field label="Ordre"><input className="input" inputMode="numeric" value={catEdit.sortOrder ?? ''} onChange={(e) => setCatEdit({ ...catEdit, sortOrder: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary w-full" disabled={saveCat.isPending || !catEdit.name} onClick={() => saveCat.mutate(catEdit)}>Enregistrer</button>
          </div>
        )}
      </Modal>

      <Modal open={!!faqEdit} onClose={() => setFaqEdit(null)} title="Question">
        {faqEdit && (
          <div className="space-y-3">
            <Field label="Question"><input className="input" value={faqEdit.question ?? ''} onChange={(e) => setFaqEdit({ ...faqEdit, question: e.target.value })} /></Field>
            <Field label="Réponse"><textarea className="input" rows={5} value={faqEdit.answer ?? ''} onChange={(e) => setFaqEdit({ ...faqEdit, answer: e.target.value })} /></Field>
            <div className="flex items-center gap-4">
              <div className="w-24"><Field label="Ordre"><input className="input" inputMode="numeric" value={faqEdit.sortOrder ?? ''} onChange={(e) => setFaqEdit({ ...faqEdit, sortOrder: Number(e.target.value.replace(/\D/g, '')) })} /></Field></div>
              <label className="mt-5 flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={faqEdit.isActive !== false} onChange={(e) => setFaqEdit({ ...faqEdit, isActive: e.target.checked })} /> Active</label>
            </div>
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary w-full" disabled={saveFaq.isPending || !faqEdit.question || !faqEdit.answer} onClick={() => saveFaq.mutate(faqEdit)}>Enregistrer</button>
          </div>
        )}
      </Modal>

      <Modal open={!!bannerEdit} onClose={() => setBannerEdit(null)} title={bannerEdit?.id ? 'Modifier la bannière' : 'Nouvelle bannière'}>
        {bannerEdit && (
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Titre"><input className="input" value={bannerEdit.title ?? ''} onChange={(e) => setBannerEdit({ ...bannerEdit, title: e.target.value })} /></Field>
              <Field label="Sous-titre"><input className="input" value={bannerEdit.subtitle ?? ''} onChange={(e) => setBannerEdit({ ...bannerEdit, subtitle: e.target.value })} /></Field>
            </div>
            <Field label="Image">
              <div className="flex items-center gap-3">
                {bannerEdit.imageDesktopUrl && <img src={assetUrl(bannerEdit.imageDesktopUrl)} alt="" className="h-14 w-28 rounded object-cover" />}
                <button className="btn-secondary !min-h-[36px] text-xs" onClick={() => bannerFile.current?.click()}>{bannerEdit.imageDesktopUrl ? 'Changer' : 'Téléverser'}</button>
                <input ref={bannerFile} type="file" accept="image/*" className="hidden" onChange={(e) => { if (e.target.files?.[0]) void uploadBannerImage(e.target.files[0]); e.target.value = ''; }} />
              </div>
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Lien (ex. /catalogue)"><input className="input font-mono" value={bannerEdit.linkUrl ?? ''} onChange={(e) => setBannerEdit({ ...bannerEdit, linkUrl: e.target.value })} /></Field>
              <Field label="Libellé du bouton"><input className="input" value={bannerEdit.ctaLabel ?? ''} onChange={(e) => setBannerEdit({ ...bannerEdit, ctaLabel: e.target.value })} /></Field>
              <Field label="Début"><input className="input" type="datetime-local" value={bannerEdit.startsAt ?? ''} onChange={(e) => setBannerEdit({ ...bannerEdit, startsAt: e.target.value })} /></Field>
              <Field label="Fin"><input className="input" type="datetime-local" value={bannerEdit.endsAt ?? ''} onChange={(e) => setBannerEdit({ ...bannerEdit, endsAt: e.target.value })} /></Field>
              <Field label="Ordre"><input className="input" inputMode="numeric" value={bannerEdit.sortOrder ?? ''} onChange={(e) => setBannerEdit({ ...bannerEdit, sortOrder: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
              <label className="mt-5 flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={bannerEdit.isActive !== false} onChange={(e) => setBannerEdit({ ...bannerEdit, isActive: e.target.checked })} /> Active</label>
            </div>
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary w-full" disabled={saveBanner.isPending || !bannerEdit.title || !bannerEdit.imageDesktopUrl} onClick={() => saveBanner.mutate({ ...bannerEdit, startsAt: bannerEdit.startsAt || null, endsAt: bannerEdit.endsAt || null })}>Enregistrer</button>
          </div>
        )}
      </Modal>

      <Modal open={!!tplEdit} onClose={() => setTplEdit(null)} title={`Gabarit ${tplEdit ? `${TEMPLATE_NAMES[tplEdit.code]} · ${tplEdit.channel}` : ''}`} wide>
        {tplEdit && (
          <div className="space-y-3">
            <Field label="Sujet (e-mail)"><input className="input" value={tplEdit.subject ?? ''} onChange={(e) => setTplEdit({ ...tplEdit, subject: e.target.value })} /></Field>
            <Field label="Corps — variables : customerName, orderNumber, total, amount, trackingUrl, stepLabel, shopName…">
              <textarea className="input font-mono text-xs" rows={10} value={tplEdit.body} onChange={(e) => setTplEdit({ ...tplEdit, body: e.target.value })} />
            </Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={tplEdit.isActive} onChange={(e) => setTplEdit({ ...tplEdit, isActive: e.target.checked })} /> Actif</label>
            {tplPreview && (
              <div className="rounded-xl bg-ivory p-3 text-sm">
                <p className="mb-1 text-xs font-semibold uppercase text-ink-soft"><Eye className="mr-1 inline h-3 w-3" /> Aperçu</p>
                {tplPreview.subject && <p className="font-semibold">{tplPreview.subject}</p>}
                <p className="whitespace-pre-wrap">{tplPreview.body}</p>
              </div>
            )}
            {error && <Alert tone="error">{error}</Alert>}
            <div className="flex gap-2">
              <button className="btn-secondary flex-1" disabled={previewTpl.isPending} onClick={() => previewTpl.mutate(tplEdit)}>Aperçu</button>
              <button className="btn-primary flex-1" disabled={saveTpl.isPending || !tplEdit.body} onClick={() => saveTpl.mutate(tplEdit)}>Enregistrer</button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
