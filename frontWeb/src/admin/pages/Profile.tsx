import { formatDate, formatPhone, ROLE_DEFINITIONS, RoleCode } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, MonitorX, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Alert, Badge, EmptyState, Field, PageLoader } from '@/admin/components/ui';
import { api, errorMessage } from '@/admin/lib/api';
import { useUiStore } from '@/admin/store/ui';

interface Profile { id: number; email: string; fullName: string; phone: string | null; roles: RoleCode[]; twoFactorEnabled: boolean; lastLoginAt: string | null; createdAt: string }
interface Session { id: number; createdAt: string; ip: string | null; userAgent: string | null; expiresAt: string }
interface TotpSetup { secret: string; otpauthUrl: string; qrCodeDataUrl: string }

export default function Profile() {
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [pw, setPw] = useState({ current: '', next: '', confirm: '' });
  const [totp, setTotp] = useState<TotpSetup | null>(null);
  const [totpCode, setTotpCode] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { data: me, isLoading } = useQuery({ queryKey: ['me'], queryFn: () => api<Profile>('/admin/auth/me') });
  const { data: sessions } = useQuery({ queryKey: ['my-sessions'], queryFn: () => api<Session[]>('/admin/users/me/sessions') });

  const changePw = useMutation({
    mutationFn: () => api('/admin/auth/me/password', { method: 'POST', body: { currentPassword: pw.current, newPassword: pw.next } }),
    onSuccess: () => { notify('Mot de passe modifié'); setPw({ current: '', next: '', confirm: '' }); setError(null); },
    onError: (e) => setError(errorMessage(e)),
  });
  const startTotp = useMutation({ mutationFn: () => api<TotpSetup>('/admin/auth/me/2fa/setup', { method: 'POST' }), onSuccess: setTotp, onError: (e) => notify(errorMessage(e), 'error') });
  const enableTotp = useMutation({
    mutationFn: () => api('/admin/auth/me/2fa/enable', { method: 'POST', body: { code: totpCode } }),
    onSuccess: () => { notify('Double authentification activée'); setTotp(null); setTotpCode(''); qc.invalidateQueries({ queryKey: ['me'] }); qc.invalidateQueries({ queryKey: ['my-sessions'] }); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });
  const revoke = (id: number) => api(`/admin/users/${me!.id}/sessions/${id}`, { method: 'DELETE' }).then(() => qc.invalidateQueries({ queryKey: ['my-sessions'] })).catch((e) => notify(errorMessage(e), 'error'));

  if (isLoading || !me) return <PageLoader />;

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <h1 className="text-2xl">Mon profil</h1>

      <section className="card space-y-2 p-5 text-sm">
        <div className="flex items-center justify-between">
          <p className="font-semibold">{me.fullName}</p>
          {me.roles.map((r) => <Badge key={r} tone="gold">{ROLE_DEFINITIONS[r]?.name ?? r}</Badge>)}
        </div>
        <p className="text-ink-soft">{me.email} {me.phone ? `· ${formatPhone(me.phone)}` : ''}</p>
        <p className="text-xs text-ink-soft">Compte créé le {formatDate(me.createdAt)} · dernière connexion {me.lastLoginAt ? formatDate(me.lastLoginAt, true) : '—'}</p>
      </section>

      <section className="card space-y-3 p-5">
        <h2 className="flex items-center gap-2 font-semibold"><KeyRound className="h-4 w-4" /> Mot de passe</h2>
        {error && <Alert tone="error">{error}</Alert>}
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label="Actuel"><input className="input" type="password" autoComplete="current-password" value={pw.current} onChange={(e) => setPw({ ...pw, current: e.target.value })} /></Field>
          <Field label="Nouveau"><input className="input" type="password" autoComplete="new-password" value={pw.next} onChange={(e) => setPw({ ...pw, next: e.target.value })} /></Field>
          <Field label="Confirmation"><input className="input" type="password" autoComplete="new-password" value={pw.confirm} onChange={(e) => setPw({ ...pw, confirm: e.target.value })} /></Field>
        </div>
        <button className="btn-primary" disabled={changePw.isPending || !pw.current || pw.next.length < 12 || pw.next !== pw.confirm} onClick={() => changePw.mutate()}>Modifier</button>
      </section>

      <section className="card space-y-3 p-5">
        <h2 className="flex items-center gap-2 font-semibold"><ShieldCheck className="h-4 w-4" /> Double authentification (2FA)</h2>
        {me.twoFactorEnabled ? (
          <Badge tone="success"><ShieldCheck className="mr-1 h-3 w-3" /> Activée</Badge>
        ) : totp ? (
          <div className="space-y-3">
            <p className="text-sm">Scannez ce code avec votre application d'authentification, puis saisissez le code à 6 chiffres :</p>
            <div className="flex flex-wrap items-center gap-4">
              <img src={totp.qrCodeDataUrl} alt="QR code 2FA" className="h-36 w-36 rounded-lg border border-sand" />
              <div className="text-xs">
                <p className="mb-1 text-ink-soft">Ou saisir manuellement :</p>
                <code className="rounded bg-ivory px-2 py-1 font-mono">{totp.secret}</code>
              </div>
            </div>
            <Field label="Code de vérification"><input className="input max-w-[160px] text-center font-mono text-lg tracking-widest" inputMode="numeric" maxLength={6} value={totpCode} onChange={(e) => setTotpCode(e.target.value.replace(/\D/g, ''))} /></Field>
            <button className="btn-primary" disabled={enableTotp.isPending || totpCode.length !== 6} onClick={() => enableTotp.mutate()}>Activer la 2FA</button>
          </div>
        ) : (
          <button className="btn-secondary" disabled={startTotp.isPending} onClick={() => startTotp.mutate()}>Configurer la 2FA</button>
        )}
      </section>

      <section className="card space-y-3 p-5">
        <h2 className="flex items-center gap-2 font-semibold"><MonitorX className="h-4 w-4" /> Sessions actives</h2>
        {!sessions?.length ? <EmptyState title="Aucune session" text="Aucune autre session n'est active." /> : (
          <ul className="space-y-2">
            {sessions.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-3 rounded-xl bg-ivory/60 px-3 py-2 text-sm">
                <div>
                  <p className="text-xs">{s.userAgent ?? 'Appareil inconnu'}</p>
                  <p className="text-[10px] text-ink-soft">{s.ip ?? ''} · ouverte le {formatDate(s.createdAt, true)} · expire {formatDate(s.expiresAt, true)}</p>
                </div>
                <button className="btn-ghost !min-h-[30px] !px-2 text-xs text-danger" onClick={() => revoke(s.id)}>Révoquer</button>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
