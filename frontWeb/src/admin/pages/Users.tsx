import { formatDate, formatPhone } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, MonitorX, Pencil, Plus, ShieldCheck, Trash2, Unlock } from 'lucide-react';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Alert, Badge, DataTable, EmptyState, Field, Modal, PageLoader } from '@/admin/components/ui';
import { api, errorMessage } from '@/admin/lib/api';
import { useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';
import { AdminUser } from './types';

interface RoleRow { code: string; name: string; description: string | null; permissions: { code: string; name: string }[] }
interface SessionRow { id: number; createdAt: string; ip: string | null; userAgent: string | null; expiresAt: string; current?: boolean }

export default function Users() {
  const me = useAuthStore((s) => s.user);
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'utilisateurs';
  const [userEdit, setUserEdit] = useState<(Partial<AdminUser> & { roleCodes?: string[]; password?: string; email?: string; fullName?: string; phone?: string | null }) | null>(null);
  const [sessionsFor, setSessionsFor] = useState<AdminUser | null>(null);
  const [resetPwFor, setResetPwFor] = useState<AdminUser | null>(null);
  const [newPw, setNewPw] = useState('');
  const [error, setError] = useState<string | null>(null);

  const users = useQuery({ queryKey: ['users'], queryFn: () => api<AdminUser[]>('/admin/users') });
  const roles = useQuery({ queryKey: ['roles'], queryFn: () => api<RoleRow[]>('/admin/roles') });
  const sessions = useQuery({ queryKey: ['sessions', sessionsFor?.id], queryFn: () => api<SessionRow[]>(`/admin/users/${sessionsFor!.id}/sessions`), enabled: !!sessionsFor });

  const refresh = () => qc.invalidateQueries({ queryKey: ['users', 'roles', 'sessions'] });
  const onErr = (e: unknown) => setError(errorMessage(e));

  const saveUser = useMutation({
    mutationFn: (u: NonNullable<typeof userEdit>) => u.id
      ? api(`/admin/users/${u.id}`, { method: 'PATCH', body: { fullName: u.fullName, phone: u.phone, isActive: u.isActive, roleCodes: u.roleCodes } })
      : api('/admin/users', { method: 'POST', body: { email: u.email, fullName: u.fullName, phone: u.phone, password: u.password, roleCodes: u.roleCodes } }),
    onSuccess: () => { notify('Utilisateur enregistré'); setUserEdit(null); refresh(); },
    onError: onErr,
  });
  const resetPw = useMutation({
    mutationFn: () => api(`/admin/users/${resetPwFor!.id}/reset-password`, { method: 'POST', body: { password: newPw } }),
    onSuccess: () => { notify('Mot de passe réinitialisé'); setResetPwFor(null); setNewPw(''); },
    onError: onErr,
  });
  const action = (path: string, label: string) => api(path, { method: 'POST' }).then(() => { notify(label); refresh(); }).catch((e) => notify(errorMessage(e), 'error'));
  const revokeSession = (u: AdminUser, t: SessionRow) => api(`/admin/users/${u.id}/sessions/${t.id}`, { method: 'DELETE' }).then(refresh).catch((e) => notify(errorMessage(e), 'error'));

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Utilisateurs & rôles</h1>
        <div className="flex gap-2">
          {tab === 'utilisateurs' && <button className="btn-primary" onClick={() => { setUserEdit({ fullName: '', email: '', phone: '', password: '', roleCodes: [], isActive: true }); setError(null); }}><Plus className="h-4 w-4" /> Utilisateur</button>}
        </div>
      </div>
      <div className="flex gap-1 rounded-lg bg-sand/50 p-1">
        {[['utilisateurs', 'Utilisateurs'], ['roles', 'Rôles & permissions']].map(([v, l]) => (
          <button key={v} role="tab" aria-selected={tab === v} onClick={() => setParams(v === 'utilisateurs' ? {} : { tab: v }, { replace: true })} className={`rounded-md px-3 py-1.5 text-sm font-semibold ${tab === v ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}>{l}</button>
        ))}
      </div>

      {tab === 'utilisateurs' && (users.isLoading ? <PageLoader /> : (
        <DataTable head={['Nom', 'E-mail', 'Téléphone', 'Rôles', '2FA', 'Dernière connexion', 'Statut', '']}>
          {users.data?.map((u) => (
            <tr key={u.id}>
              <td className="td font-medium">{u.fullName}{u.id === me?.id && <Badge tone="gold"> vous</Badge>}</td>
              <td className="td text-xs">{u.email}</td>
              <td className="td text-xs">{u.phone ? formatPhone(u.phone) : '—'}</td>
              <td className="td text-xs">{u.roles.map((r) => r.name).join(', ')}</td>
              <td className="td">{u.twoFactorEnabled ? <ShieldCheck className="h-4 w-4 text-success" aria-label="2FA activée" /> : '—'}</td>
              <td className="td text-xs">{u.lastLoginAt ? formatDate(u.lastLoginAt, true) : 'Jamais'}</td>
              <td className="td">
                <Badge tone={u.isActive ? 'success' : 'danger'}>{u.isActive ? 'Actif' : 'Inactif'}</Badge>
                {u.lockedUntil && new Date(u.lockedUntil) > new Date() && <Badge tone="warning"> verrouillé</Badge>}
              </td>
              <td className="td whitespace-nowrap">
                <button className="btn-ghost !min-h-[30px] !px-2" title="Sessions" onClick={() => setSessionsFor(u)}><MonitorX className="h-4 w-4" /></button>
                <button className="btn-ghost !min-h-[30px] !px-2" title="Modifier" onClick={() => { setUserEdit({ ...u, roleCodes: u.roles.map((r) => r.code) }); setError(null); }}><Pencil className="h-4 w-4" /></button>
                <button className="btn-ghost !min-h-[30px] !px-2" title="Réinitialiser le mot de passe" onClick={() => { setResetPwFor(u); setNewPw(''); setError(null); }}><KeyRound className="h-4 w-4" /></button>
                {u.twoFactorEnabled && <button className="btn-ghost !min-h-[30px] !px-2" title="Réinitialiser la 2FA" onClick={() => action(`/admin/users/${u.id}/reset-2fa`, '2FA réinitialisée')}><ShieldCheck className="h-4 w-4" /></button>}
                {u.lockedUntil && <button className="btn-ghost !min-h-[30px] !px-2" title="Déverrouiller" onClick={() => action(`/admin/users/${u.id}/unlock`, 'Compte déverrouillé')}><Unlock className="h-4 w-4" /></button>}
                {u.id !== me?.id && <button className="btn-ghost !min-h-[30px] !px-2 text-danger" title="Supprimer" onClick={() => confirm(`Supprimer ${u.fullName} ?`) && api(`/admin/users/${u.id}`, { method: 'DELETE' }).then(() => { notify('Supprimé'); refresh(); }).catch((e) => notify(errorMessage(e), 'error'))}><Trash2 className="h-4 w-4" /></button>}
              </td>
            </tr>
          ))}
        </DataTable>
      ))}

      {tab === 'roles' && (roles.isLoading ? <PageLoader /> : (
        <div className="space-y-3">
          <p className="text-xs text-ink-soft">Les rôles et leurs permissions sont définis dans le code de l'application et ne sont pas modifiables ici.</p>
          <div className="grid gap-4 md:grid-cols-2">
            {roles.data?.map((r) => (
              <div key={r.code} className="card p-4">
                <p className="font-semibold">{r.name} <span className="font-mono text-xs text-ink-soft">({r.code})</span></p>
                <p className="text-xs text-ink-soft">{r.description ?? ''}</p>
                <div className="mt-2 flex flex-wrap gap-1">
                  {r.permissions.length ? r.permissions.map((p) => <Badge key={p.code} tone="muted">{p.name}</Badge>) : <span className="text-xs text-ink-soft">Aucune permission</span>}
                </div>
              </div>
            ))}
          </div>
        </div>
      ))}

      {/* Modales */}
      <Modal open={!!userEdit} onClose={() => setUserEdit(null)} title={userEdit?.id ? `Modifier ${userEdit.fullName}` : 'Nouvel utilisateur'}>
        {userEdit && (
          <div className="space-y-3">
            <Field label="Nom complet"><input className="input" value={userEdit.fullName ?? ''} onChange={(e) => setUserEdit({ ...userEdit, fullName: e.target.value })} /></Field>
            {!userEdit.id && <Field label="E-mail"><input className="input" type="email" value={userEdit.email ?? ''} onChange={(e) => setUserEdit({ ...userEdit, email: e.target.value })} /></Field>}
            <Field label="Téléphone"><input className="input" value={userEdit.phone ?? ''} onChange={(e) => setUserEdit({ ...userEdit, phone: e.target.value })} /></Field>
            {!userEdit.id && <Field label="Mot de passe provisoire" hint="12 caractères minimum, majuscule, minuscule, chiffre et symbole."><input className="input" type="text" value={userEdit.password ?? ''} onChange={(e) => setUserEdit({ ...userEdit, password: e.target.value })} /></Field>}
            <Field label="Rôles">
              <div className="flex flex-wrap gap-2">
                {roles.data?.map((r) => (
                  <label key={r.code} className={`badge cursor-pointer ${userEdit.roleCodes?.includes(r.code) ? 'bg-gold/20 text-gold-dark ring-1 ring-gold' : 'bg-sand text-ink-soft'}`}>
                    <input type="checkbox" className="sr-only" checked={userEdit.roleCodes?.includes(r.code) ?? false} onChange={(e) => setUserEdit({ ...userEdit, roleCodes: e.target.checked ? [...(userEdit.roleCodes ?? []), r.code] : (userEdit.roleCodes ?? []).filter((c) => c !== r.code) })} />
                    {r.name}
                  </label>
                ))}
              </div>
            </Field>
            {userEdit.id && userEdit.id !== me?.id && (
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={userEdit.isActive} onChange={(e) => setUserEdit({ ...userEdit, isActive: e.target.checked })} /> Compte actif</label>
            )}
            {error && <Alert tone="error">{error}</Alert>}
            <button className="btn-primary w-full" disabled={saveUser.isPending || !userEdit.fullName || !(userEdit.roleCodes?.length)} onClick={() => saveUser.mutate(userEdit)}>Enregistrer</button>
          </div>
        )}
      </Modal>

      <Modal open={!!resetPwFor} onClose={() => setResetPwFor(null)} title={`Réinitialiser le mot de passe — ${resetPwFor?.fullName}`}>
        <div className="space-y-3">
          <Field label="Nouveau mot de passe" hint="12 caractères minimum, majuscule, minuscule, chiffre et symbole. Toutes les sessions seront fermées."><input className="input" value={newPw} onChange={(e) => setNewPw(e.target.value)} /></Field>
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary w-full" disabled={resetPw.isPending || newPw.length < 12} onClick={() => resetPw.mutate()}>Réinitialiser</button>
        </div>
      </Modal>

      <Modal open={!!sessionsFor} onClose={() => setSessionsFor(null)} title={`Sessions — ${sessionsFor?.fullName}`}>
        {sessions.isLoading ? <PageLoader /> : !sessions.data?.length ? (
          <EmptyState title="Aucune session" text="Aucune session active pour cet utilisateur." />
        ) : (
          <ul className="space-y-2">
            {sessions.data.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-3 rounded-xl bg-ivory/60 px-3 py-2 text-sm">
                <div>
                  <p className="text-xs">{s.userAgent ?? 'Appareil inconnu'}</p>
                  <p className="text-[10px] text-ink-soft">{s.ip ?? ''} · {formatDate(s.createdAt, true)} → {formatDate(s.expiresAt, true)}</p>
                </div>
                <button className="btn-ghost !min-h-[30px] !px-2 text-xs text-danger" onClick={() => sessionsFor && revokeSession(sessionsFor, s)}>Révoquer</button>
              </li>
            ))}
          </ul>
        )}
      </Modal>
    </div>
  );
}
