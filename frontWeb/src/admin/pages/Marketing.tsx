import { CouponType, formatDate, formatFCFA, Paginated } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { MailX, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Alert, Badge, DataTable, Field, Modal, PageLoader, Pagination } from '@/admin/components/ui';
import { api, download, errorMessage } from '@/admin/lib/api';
import { useUiStore } from '@/admin/store/ui';

interface CouponRow {
  id: number; code: string; description: string | null; type: CouponType; value: number;
  minSubtotal: number; maxDiscount: number | null; usedCount: number; usageLimit: number | null;
  usageLimitPerCustomer: number | null; startsAt: string | null; endsAt: string | null; isActive: boolean; createdAt: string;
}
interface SubscriberRow { id: number; email: string | null; phone: string | null; consentAt: string; source: string; unsubscribedAt: string | null }

const TYPE_LABELS: Record<CouponType, string> = { PERCENT: 'Pourcentage', FIXED: 'Montant fixe', FREE_SHIPPING: 'Livraison offerte' };
const TABS = ['Coupons', 'Newsletter'] as const;

export default function Marketing() {
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [params, setParams] = useSearchParams();
  const tab = (params.get('tab') as (typeof TABS)[number]) ?? 'Coupons';
  const [edit, setEdit] = useState<Partial<CouponRow> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const page = Number(params.get('page') ?? 1);
  const sq = params.get('q') ?? '';

  const { data: coupons, isLoading: lc } = useQuery({ queryKey: ['coupons', page, sq], queryFn: () => api<Paginated<CouponRow>>('/admin/coupons', { query: { page, q: sq, limit: 25 } }), enabled: tab === 'Coupons' });
  const { data: subs, isLoading: ls } = useQuery({ queryKey: ['newsletter', page, sq], queryFn: () => api<Paginated<SubscriberRow>>('/admin/newsletter', { query: { page, q: sq, limit: 25 } }), enabled: tab === 'Newsletter' });

  const save = useMutation({
    mutationFn: (c: Partial<CouponRow>) => (c.id ? api(`/admin/coupons/${c.id}`, { method: 'PATCH', body: c }) : api('/admin/coupons', { method: 'POST', body: c })),
    onSuccess: () => { notify('Coupon enregistré'); setEdit(null); qc.invalidateQueries({ queryKey: ['coupons'] }); },
    onError: (e) => setError(errorMessage(e)),
  });
  const del = useMutation({
    mutationFn: (id: number) => api(`/admin/coupons/${id}`, { method: 'DELETE' }),
    onSuccess: () => { notify('Coupon supprimé'); qc.invalidateQueries({ queryKey: ['coupons'] }); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });
  const unsub = useMutation({
    mutationFn: (id: number) => api(`/admin/newsletter/${id}`, { method: 'DELETE' }),
    onSuccess: () => { notify('Désinscrit'); qc.invalidateQueries({ queryKey: ['newsletter'] }); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });

  const setParam = (k: string, v: string) => {
    const n = new URLSearchParams(params);
    if (v) n.set(k, v); else n.delete(k);
    if (k !== 'page') n.delete('page');
    setParams(n, { replace: true });
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Marketing</h1>
        {tab === 'Coupons' && <button className="btn-primary" onClick={() => { setEdit({ code: '', type: CouponType.PERCENT, value: 0, minSubtotal: 0, isActive: true }); setError(null); }}><Plus className="h-4 w-4" /> Coupon</button>}
        {tab === 'Newsletter' && <button className="btn-secondary" onClick={() => download('/admin/exports/newsletter', { format: 'csv' }).catch((e) => notify(errorMessage(e), 'error'))}>Exporter (CSV)</button>}
      </div>
      <div className="flex gap-1 rounded-lg bg-sand/50 p-1">
        {TABS.map((t) => <button key={t} role="tab" aria-selected={tab === t} onClick={() => setParam('tab', t)} className={`rounded-md px-3 py-1.5 text-sm font-semibold ${tab === t ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}>{t}</button>)}
      </div>
      <input className="input max-w-xs" placeholder="Rechercher…" value={sq} onChange={(e) => setParam('q', e.target.value)} />

      {tab === 'Coupons' && (
        lc ? <PageLoader /> : (
          <>
            <DataTable head={['Code', 'Type', 'Valeur', 'Minimum', 'Validité', 'Utilisations', 'Actif', '']}>
              {coupons?.data.map((c) => (
                <tr key={c.id}>
                  <td className="td font-mono font-semibold">{c.code}<p className="text-xs font-normal text-ink-soft">{c.description ?? ''}</p></td>
                  <td className="td text-xs">{TYPE_LABELS[c.type]}</td>
                  <td className="td font-semibold">{c.type === CouponType.PERCENT ? `${c.value} %` : c.type === CouponType.FIXED ? formatFCFA(c.value) : '—'}</td>
                  <td className="td text-xs">{c.minSubtotal ? formatFCFA(c.minSubtotal) : '—'}{c.maxDiscount ? ` · max ${formatFCFA(c.maxDiscount)}` : ''}</td>
                  <td className="td text-xs">{c.startsAt ? formatDate(c.startsAt) : '—'} → {c.endsAt ? formatDate(c.endsAt) : '∞'}</td>
                  <td className="td">{c.usedCount}{c.usageLimit ? `/${c.usageLimit}` : ''}</td>
                  <td className="td"><Badge tone={c.isActive ? 'success' : 'muted'}>{c.isActive ? 'Oui' : 'Non'}</Badge></td>
                  <td className="td whitespace-nowrap">
                    <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => { setEdit({ ...c, startsAt: c.startsAt?.slice(0, 16) ?? '', endsAt: c.endsAt?.slice(0, 16) ?? '' } as Partial<CouponRow>); setError(null); }} aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                    <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => confirm(`Supprimer ${c.code} ?`) && del.mutate(c.id)} aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                  </td>
                </tr>
              ))}
            </DataTable>
            {coupons && <Pagination page={page} limit={25} total={coupons.meta.total} onPage={(p) => setParam('page', String(p))} />}
          </>
        )
      )}

      {tab === 'Newsletter' && (
        ls ? <PageLoader /> : (
          <>
            <DataTable head={['E-mail', 'Téléphone', 'Consentement', 'Source', 'Statut', '']}>
              {subs?.data.map((s) => (
                <tr key={s.id}>
                  <td className="td">{s.email ?? '—'}</td>
                  <td className="td">{s.phone ?? '—'}</td>
                  <td className="td text-xs">{formatDate(s.consentAt, true)}</td>
                  <td className="td text-xs">{s.source}</td>
                  <td className="td"><Badge tone={s.unsubscribedAt ? 'muted' : 'success'}>{s.unsubscribedAt ? `Désinscrit ${formatDate(s.unsubscribedAt)}` : 'Abonné'}</Badge></td>
                  <td className="td">{!s.unsubscribedAt && <button className="btn-ghost !min-h-[30px] !px-2 text-xs text-danger" onClick={() => unsub.mutate(s.id)}><MailX className="h-4 w-4" /> Désinscrire</button>}</td>
                </tr>
              ))}
            </DataTable>
            {subs && <Pagination page={page} limit={25} total={subs.meta.total} onPage={(p) => setParam('page', String(p))} />}
          </>
        )
      )}

      <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.id ? `Coupon ${edit.code}` : 'Nouveau coupon'}>
        {edit && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Code"><input className="input font-mono uppercase" value={edit.code ?? ''} onChange={(e) => setEdit({ ...edit, code: e.target.value.toUpperCase() })} /></Field>
            <Field label="Type"><select className="input" value={edit.type} onChange={(e) => setEdit({ ...edit, type: e.target.value as CouponType })}>
              {Object.entries(TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select></Field>
            <Field label={edit.type === CouponType.PERCENT ? 'Remise (%)' : 'Montant (FCFA)'}><input className="input" inputMode="numeric" value={edit.value ?? ''} onChange={(e) => setEdit({ ...edit, value: Number(e.target.value.replace(/\D/g, '')) })} disabled={edit.type === CouponType.FREE_SHIPPING} /></Field>
            <Field label="Sous-total minimum"><input className="input" inputMode="numeric" value={edit.minSubtotal ?? ''} onChange={(e) => setEdit({ ...edit, minSubtotal: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
            <Field label="Remise max (%)"><input className="input" inputMode="numeric" value={edit.maxDiscount ?? ''} onChange={(e) => setEdit({ ...edit, maxDiscount: e.target.value ? Number(e.target.value.replace(/\D/g, '')) : null })} /></Field>
            <Field label="Description"><input className="input" value={edit.description ?? ''} onChange={(e) => setEdit({ ...edit, description: e.target.value })} /></Field>
            <Field label="Début"><input className="input" type="datetime-local" value={String(edit.startsAt ?? '')} onChange={(e) => setEdit({ ...edit, startsAt: e.target.value })} /></Field>
            <Field label="Fin"><input className="input" type="datetime-local" value={String(edit.endsAt ?? '')} onChange={(e) => setEdit({ ...edit, endsAt: e.target.value })} /></Field>
            <Field label="Nb utilisations max"><input className="input" inputMode="numeric" value={edit.usageLimit ?? ''} onChange={(e) => setEdit({ ...edit, usageLimit: e.target.value ? Number(e.target.value.replace(/\D/g, '')) : null })} /></Field>
            <Field label="Max par client"><input className="input" inputMode="numeric" value={edit.usageLimitPerCustomer ?? ''} onChange={(e) => setEdit({ ...edit, usageLimitPerCustomer: e.target.value ? Number(e.target.value.replace(/\D/g, '')) : null })} /></Field>
            <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" className="h-4 w-4 accent-gold" checked={edit.isActive !== false} onChange={(e) => setEdit({ ...edit, isActive: e.target.checked })} /> Actif</label>
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary sm:col-span-2" disabled={save.isPending || !edit.code} onClick={() => save.mutate({ ...edit, startsAt: edit.startsAt || null, endsAt: edit.endsAt || null })}>Enregistrer</button>
          </div>
        )}
      </Modal>
    </div>
  );
}
