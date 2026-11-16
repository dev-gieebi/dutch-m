import { DeliveryZoneDto, formatFCFA, formatPhone, Permission } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, MapPin, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert, Badge, DataTable, Field, Modal, PageLoader } from '@/admin/components/ui';
import { api, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';
import { Planning } from './types';

interface ZoneRow extends DeliveryZoneDto { estimatedDays: number; isActive: boolean; sortOrder: number }

const emptyZone = { city: 'Libreville', name: '', fee: 0, estimatedDelay: '24–48 h', estimatedDays: 2, isActive: true, sortOrder: 0 };

export default function PlanningPage() {
  const user = useAuthStore((s) => s.user);
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [zoneForm, setZoneForm] = useState<(typeof emptyZone) & { id?: number } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data: planning, isLoading } = useQuery({ queryKey: ['planning', date], queryFn: () => api<Planning>('/admin/deliveries/planning', { query: { date } }) });
  const { data: zones } = useQuery({ queryKey: ['zones-admin'], queryFn: () => api<ZoneRow[]>('/admin/delivery/zones') });
  const canWrite = hasPermission(user, Permission.DELIVERY_WRITE);

  const saveZone = useMutation({
    mutationFn: (z: typeof zoneForm) => (z?.id ? api(`/admin/delivery/zones/${z.id}`, { method: 'PATCH', body: { ...z, fee: Number(z.fee), estimatedDays: Number(z.estimatedDays), sortOrder: Number(z.sortOrder) } }) : api('/admin/delivery/zones', { method: 'POST', body: { ...z, fee: Number(z!.fee), estimatedDays: Number(z!.estimatedDays), sortOrder: Number(z!.sortOrder) } })),
    onSuccess: () => { notify('Zone enregistrée'); setZoneForm(null); qc.invalidateQueries({ queryKey: ['zones-admin'] }); },
    onError: (e) => setError(errorMessage(e)),
  });
  const removeZone = useMutation({
    mutationFn: (id: number) => api(`/admin/delivery/zones/${id}`, { method: 'DELETE' }),
    onSuccess: () => { notify('Zone désactivée'); qc.invalidateQueries({ queryKey: ['zones-admin'] }); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Livraisons</h1>
        <Field label="Date"><input className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
      </div>
      {isLoading ? <PageLoader /> : (
        <div className="grid gap-4 lg:grid-cols-2">
          {planning?.drivers.map(({ driver, shipments }) => (
            <div key={driver?.id ?? 'none'} className="card p-4">
              <h2 className="mb-3 flex items-center justify-between font-semibold">
                {driver ? driver.fullName : 'Non affectées'}
                <Badge tone="gold">{shipments.length} tournée{shipments.length > 1 ? 's' : ''}</Badge>
              </h2>
              {shipments.length ? (
                <ul className="space-y-2">
                  {shipments.map((s) => (
                    <li key={s.id} className="flex items-center gap-3 rounded-lg border border-sand p-3 text-sm">
                      <div className="min-w-0 flex-1">
                        <Link to={`/admin/commandes/${s.orderId}`} className="font-mono text-xs font-semibold text-gold-dark hover:underline">{s.orderNumber}</Link>
                        <p className="font-medium">{s.contactName} · <a href={`tel:${s.contactPhone}`} className="text-gold-dark">{formatPhone(s.contactPhone)}</a></p>
                        <p className="truncate text-xs text-ink-soft"><MapPin className="mr-1 inline h-3 w-3" />{s.address}</p>
                        {s.deliveredAt && <Badge tone="success">Livrée</Badge>}
                      </div>
                      <Badge tone="muted">{s.deliveryCode}</Badge>
                    </li>
                  ))}
                </ul>
              ) : <p className="text-sm text-ink-soft">Aucune livraison planifiée.</p>}
            </div>
          ))}
          {planning && !planning.drivers.length && <Alert tone="info"><CalendarDays className="mr-1 inline h-4 w-4" /> Aucune livraison à domicile planifiée pour cette date.</Alert>}
        </div>
      )}

      {canWrite && (
        <div className="card p-4">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-semibold">Zones de livraison</h2>
            <button className="btn-primary !min-h-[34px] text-xs" onClick={() => setZoneForm({ ...emptyZone })}><Plus className="h-4 w-4" /> Ajouter</button>
          </div>
          <DataTable head={['Zone', 'Ville', 'Frais', 'Délai', 'Jours', 'Active', 'Ordre', '']}>
            {zones?.map((z) => (
              <tr key={z.id}>
                <td className="td font-medium">{z.name}</td>
                <td className="td">{z.city}</td>
                <td className="td">{formatFCFA(z.fee)}</td>
                <td className="td text-xs">{z.estimatedDelay}</td>
                <td className="td">{z.estimatedDays} j</td>
                <td className="td"><Badge tone={z.isActive ? 'success' : 'muted'}>{z.isActive ? 'Oui' : 'Non'}</Badge></td>
                <td className="td">{z.sortOrder}</td>
                <td className="td whitespace-nowrap">
                  <button className="btn-ghost !min-h-[30px] !px-2" onClick={() => setZoneForm({ id: z.id, city: z.city, name: z.name, fee: z.fee, estimatedDelay: z.estimatedDelay, estimatedDays: z.estimatedDays, isActive: z.isActive, sortOrder: z.sortOrder })} aria-label={`Modifier ${z.name}`}><Pencil className="h-4 w-4" /></button>
                  <button className="btn-ghost !min-h-[30px] !px-2 text-danger" onClick={() => removeZone.mutate(z.id)} aria-label={`Désactiver ${z.name}`}><Trash2 className="h-4 w-4" /></button>
                </td>
              </tr>
            ))}
          </DataTable>
        </div>
      )}

      <Modal open={!!zoneForm} onClose={() => setZoneForm(null)} title={zoneForm?.id ? 'Modifier la zone' : 'Nouvelle zone'}>
        {zoneForm && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Ville"><input className="input" value={zoneForm.city} onChange={(e) => setZoneForm({ ...zoneForm, city: e.target.value })} /></Field>
            <Field label="Nom du quartier"><input className="input" value={zoneForm.name} onChange={(e) => setZoneForm({ ...zoneForm, name: e.target.value })} /></Field>
            <Field label="Frais (FCFA)"><input className="input" inputMode="numeric" value={zoneForm.fee} onChange={(e) => setZoneForm({ ...zoneForm, fee: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
            <Field label="Délai affiché"><input className="input" value={zoneForm.estimatedDelay} onChange={(e) => setZoneForm({ ...zoneForm, estimatedDelay: e.target.value })} /></Field>
            <Field label="Délai (jours)"><input className="input" inputMode="numeric" value={zoneForm.estimatedDays} onChange={(e) => setZoneForm({ ...zoneForm, estimatedDays: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
            <Field label="Ordre"><input className="input" inputMode="numeric" value={zoneForm.sortOrder} onChange={(e) => setZoneForm({ ...zoneForm, sortOrder: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
            <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" className="h-4 w-4 accent-gold" checked={zoneForm.isActive} onChange={(e) => setZoneForm({ ...zoneForm, isActive: e.target.checked })} /> Zone active</label>
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary sm:col-span-2" disabled={saveZone.isPending} onClick={() => saveZone.mutate(zoneForm)}>Enregistrer</button>
          </div>
        )}
      </Modal>
    </div>
  );
}
