import { DEFAULT_SETTINGS, Permission, PUBLIC_SETTING_KEYS, PublicSettings, SettingsMap, ThemeSettings } from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { RotateCcw, Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Alert, Field, PageLoader } from '@/admin/components/ui';
import { api, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';
import { applyTheme } from '@/lib/theme';

const THEME_FIELDS: [keyof ThemeSettings, string][] = [
  ['primary', 'Couleur principale (boutons, liens, titres)'],
  ['secondary', 'Couleur secondaire (accents)'],
  ['background', 'Fond des pages'],
  ['text', 'Texte'],
];

const THEME_PRESETS: { name: string; theme: ThemeSettings }[] = [
  { name: 'Or (par défaut)', theme: DEFAULT_SETTINGS.theme },
  { name: 'Rose', theme: { primary: '#C2185B', secondary: '#F48FB1', background: '#FFF7FA', text: '#3A1D2B' } },
  { name: 'Bordeaux', theme: { primary: '#8E2C48', secondary: '#D98AA0', background: '#FBF6F4', text: '#2E1A1F' } },
  { name: 'Sauge', theme: { primary: '#5E8B6A', secondary: '#A8C3A0', background: '#F7FAF6', text: '#1F2D23' } },
  { name: 'Bleu', theme: { primary: '#2F5DA8', secondary: '#8FB3E8', background: '#F6F9FD', text: '#18233A' } },
];

const HEX = /^#[0-9a-fA-F]{6}$/;

export default function Settings() {
  const user = useAuthStore((s) => s.user);
  const notify = useUiStore((s) => s.notify);
  const qc = useQueryClient();
  const canWrite = hasPermission(user, Permission.SETTINGS_WRITE);
  // Clé distincte de ['settings'] (paramètres publics de la boutique, sans notifications).
  const { data, isLoading } = useQuery({ queryKey: ['admin', 'settings'], queryFn: () => api<SettingsMap>('/admin/settings') });
  const [form, setForm] = useState<SettingsMap | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { if (data) setForm(structuredClone(data)); }, [data]);

  // Aperçu en direct des couleurs ; en quittant la page, retour au thème enregistré.
  const previewTheme = form?.theme;
  useEffect(() => { if (previewTheme && Object.values(previewTheme).every((c) => HEX.test(c))) applyTheme(previewTheme, false); }, [previewTheme]);
  useEffect(() => () => applyTheme(qc.getQueryData<PublicSettings>(['settings'])?.theme), [qc]);

  const save = useMutation({
    mutationFn: (patch: Partial<SettingsMap>) => api<SettingsMap>('/admin/settings', { method: 'PATCH', body: patch }),
    onSuccess: (all) => {
      notify('Paramètres enregistrés'); setError(null);
      qc.invalidateQueries({ queryKey: ['admin', 'settings'] });
      // Mise à jour directe : /settings/public est mis en cache 60 s par le navigateur, un refetch renverrait l'ancienne valeur.
      const pub = Object.fromEntries(PUBLIC_SETTING_KEYS.map((k) => [k, all[k]]));
      qc.setQueryData<PublicSettings>(['settings'], (old) => old && { ...old, ...pub });
    },
    onError: (e) => setError(errorMessage(e)),
  });

  if (isLoading || !form) return <PageLoader />;

  const up = <K extends keyof SettingsMap>(k: K, v: SettingsMap[K]) => setForm({ ...form, [k]: v });
  const upShop = <K extends keyof SettingsMap['shop']>(k: K, v: SettingsMap['shop'][K]) => up('shop', { ...form.shop, [k]: v });
  const upPay = (k: keyof SettingsMap['payments'], v: boolean | number) => up('payments', { ...form.payments, [k]: v });
  const checkbox = (checked: boolean, onChange: (v: boolean) => void, label: string, disabled = !canWrite) => (
    <label className={`flex items-center gap-2 text-sm ${disabled ? 'opacity-60' : ''}`}>
      <input type="checkbox" className="h-4 w-4 accent-gold" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} /> {label}
    </label>
  );
  const SaveBar = ({ patch }: { patch: Partial<SettingsMap> }) => (
    canWrite ? <button className="btn-primary" disabled={save.isPending} onClick={() => save.mutate(patch)}><Save className="h-4 w-4" /> Enregistrer</button> : null
  );

  return (
    <div className="max-w-3xl space-y-6">
      <h1 className="text-2xl">Paramètres</h1>
      {error && <Alert tone="error">{error}</Alert>}
      {!canWrite && <Alert tone="info">Mode lecture seule — la permission « Modifier les paramètres » est requise.</Alert>}

      <section className="card space-y-4 p-5">
        <h2 className="font-semibold">Boutique</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Nom"><input className="input" value={form.shop.name} disabled={!canWrite} onChange={(e) => upShop('name', e.target.value)} /></Field>
          <Field label="Slogan"><input className="input" value={form.shop.tagline} disabled={!canWrite} onChange={(e) => upShop('tagline', e.target.value)} /></Field>
          <Field label="E-mail"><input className="input" type="email" value={form.shop.email} disabled={!canWrite} onChange={(e) => upShop('email', e.target.value)} /></Field>
          <Field label="Téléphone"><input className="input" value={form.shop.phone} disabled={!canWrite} onChange={(e) => upShop('phone', e.target.value)} /></Field>
          <Field label="Numéro WhatsApp"><input className="input" value={form.whatsappNumber} disabled={!canWrite} onChange={(e) => up('whatsappNumber', e.target.value)} /></Field>
          <Field label="Adresse"><input className="input" value={form.shop.address} disabled={!canWrite} onChange={(e) => upShop('address', e.target.value)} /></Field>
          <Field label="Facebook"><input className="input" value={form.shop.socials.facebook} disabled={!canWrite} onChange={(e) => upShop('socials', { ...form.shop.socials, facebook: e.target.value })} /></Field>
          <Field label="Instagram"><input className="input" value={form.shop.socials.instagram} disabled={!canWrite} onChange={(e) => upShop('socials', { ...form.shop.socials, instagram: e.target.value })} /></Field>
          <Field label="TikTok"><input className="input" value={form.shop.socials.tiktok} disabled={!canWrite} onChange={(e) => upShop('socials', { ...form.shop.socials, tiktok: e.target.value })} /></Field>
          <Field label="Horaires affichés"><input className="input" value={form.supportHours.label} disabled={!canWrite} onChange={(e) => up('supportHours', { ...form.supportHours, label: e.target.value })} /></Field>
        </div>
        <SaveBar patch={{ shop: form.shop, whatsappNumber: form.whatsappNumber, supportHours: form.supportHours }} />
      </section>

      <section className="card space-y-4 p-5">
        <div>
          <h2 className="font-semibold">Couleurs de l'interface</h2>
          <p className="text-sm text-ink-soft">Appliquées à toute la boutique et au back-office. Aperçu en direct ; enregistrez pour les rendre visibles à tous.</p>
        </div>
        {canWrite && (
          <div className="flex flex-wrap gap-2">
            {THEME_PRESETS.map((p) => (
              <button key={p.name} type="button" className="btn-ghost !min-h-[34px] border border-sand !px-3 text-xs" onClick={() => up('theme', { ...p.theme })}>
                <span className="flex">{Object.values(p.theme).map((c, i) => <span key={i} className="-ml-1 h-4 w-4 rounded-full border border-white first:ml-0" style={{ background: c }} />)}</span>
                {p.name}
              </button>
            ))}
          </div>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          {THEME_FIELDS.map(([k, label]) => (
            <Field key={k} label={label}>
              <div className="flex items-center gap-2">
                <input type="color" className="h-11 w-14 shrink-0 cursor-pointer rounded-xl border border-sand bg-white p-1 disabled:cursor-not-allowed" value={HEX.test(form.theme[k]) ? form.theme[k] : '#000000'} disabled={!canWrite} onChange={(e) => up('theme', { ...form.theme, [k]: e.target.value.toUpperCase() })} />
                <input className="input font-mono uppercase" maxLength={7} value={form.theme[k]} disabled={!canWrite} onChange={(e) => up('theme', { ...form.theme, [k]: e.target.value.trim() })} />
              </div>
            </Field>
          ))}
        </div>
        <div className="flex flex-wrap items-center gap-3 rounded-xl bg-cream p-4">
          <button type="button" className="btn-primary" tabIndex={-1}>Bouton principal</button>
          <button type="button" className="btn-secondary" tabIndex={-1}>Bouton secondaire</button>
          <span className="badge bg-blush text-rose-dark">Badge</span>
          <span className="section-title !text-xl">Titre</span>
          <span className="text-sm text-ink-soft">Texte secondaire</span>
        </div>
        {canWrite && (
          <div className="flex flex-wrap gap-2">
            <button className="btn-primary" disabled={save.isPending} onClick={() => {
              if (!Object.values(form.theme).every((c) => HEX.test(c))) return setError('Chaque couleur doit être au format #RRGGBB');
              save.mutate({ theme: form.theme });
            }}><Save className="h-4 w-4" /> Enregistrer</button>
            <button className="btn-ghost" type="button" onClick={() => up('theme', { ...DEFAULT_SETTINGS.theme })}><RotateCcw className="h-4 w-4" /> Couleurs par défaut</button>
          </div>
        )}
      </section>

      <section className="card space-y-4 p-5">
        <h2 className="font-semibold">Paiements</h2>
        <div className="grid gap-3 sm:grid-cols-2">
          {checkbox(form.payments.cashOnDelivery, (v) => upPay('cashOnDelivery', v), 'Paiement à la livraison')}
          {checkbox(form.payments.airtelMoney, (v) => upPay('airtelMoney', v), 'Airtel Money')}
          {checkbox(form.payments.moovMoney, (v) => upPay('moovMoney', v), 'Moov Money')}
          {checkbox(form.payments.codForPreorder, (v) => upPay('codForPreorder', v), 'Paiement à la livraison autorisé en précommande')}
          {checkbox(form.payments.balanceOnDelivery, (v) => upPay('balanceOnDelivery', v), 'Solde payable à la livraison')}
          {checkbox(form.payments.depositRefundable, (v) => upPay('depositRefundable', v), 'Acompte remboursable après commande fournisseur')}
          <Field label="Acompte précommande (%)"><input className="input" inputMode="numeric" value={form.payments.depositPercent} disabled={!canWrite} onChange={(e) => upPay('depositPercent', Number(e.target.value.replace(/\D/g, '')))} /></Field>
          <Field label="Expiration mobile money (min)"><input className="input" inputMode="numeric" value={form.payments.expiryMinutes} disabled={!canWrite} onChange={(e) => upPay('expiryMinutes', Number(e.target.value.replace(/\D/g, '')))} /></Field>
          <Field label="Livraison offerte à partir de (FCFA, vide = jamais)"><input className="input" inputMode="numeric" value={form.freeShippingThreshold ?? ''} disabled={!canWrite} onChange={(e) => up('freeShippingThreshold', e.target.value ? Number(e.target.value.replace(/\D/g, '')) : null)} /></Field>
        </div>
        <SaveBar patch={{ payments: form.payments, freeShippingThreshold: form.freeShippingThreshold }} />
      </section>

      <section className="card space-y-4 p-5">
        <h2 className="font-semibold">Précommandes & retrait</h2>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Délai précommande par défaut (jours)"><input className="input" inputMode="numeric" value={form.preorder.defaultLeadTimeDays} disabled={!canWrite} onChange={(e) => up('preorder', { ...form.preorder, defaultLeadTimeDays: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
          <Field label="Qté max par commande"><input className="input" inputMode="numeric" value={form.preorder.maxQtyPerOrder} disabled={!canWrite} onChange={(e) => up('preorder', { ...form.preorder, maxQtyPerOrder: Number(e.target.value.replace(/\D/g, '')) })} /></Field>
          <Field label="Adresse de retrait"><input className="input" value={form.pickup.address} disabled={!canWrite} onChange={(e) => up('pickup', { ...form.pickup, address: e.target.value })} /></Field>
          <Field label="Horaires de retrait"><input className="input" value={form.pickup.hours} disabled={!canWrite} onChange={(e) => up('pickup', { ...form.pickup, hours: e.target.value })} /></Field>
        </div>
        {checkbox(form.pickup.enabled, (v) => up('pickup', { ...form.pickup, enabled: v }), 'Retrait en boutique disponible')}
        <SaveBar patch={{ preorder: form.preorder, pickup: form.pickup }} />
      </section>

      <section className="card space-y-4 p-5">
        <h2 className="font-semibold">Notifications & chat</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          {checkbox(form.notifications.email, (v) => up('notifications', { ...form.notifications, email: v }), 'E-mails')}
          {checkbox(form.notifications.sms, (v) => up('notifications', { ...form.notifications, sms: v }), 'SMS')}
          {checkbox(form.notifications.whatsapp, (v) => up('notifications', { ...form.notifications, whatsapp: v }), 'WhatsApp')}
        </div>
        <Field label="Message d'accueil du chat"><textarea className="input" rows={2} value={form.chat.welcomeMessage} disabled={!canWrite} onChange={(e) => up('chat', { ...form.chat, welcomeMessage: e.target.value })} /></Field>
        <Field label="Message hors horaires"><textarea className="input" rows={2} value={form.chat.offHoursMessage} disabled={!canWrite} onChange={(e) => up('chat', { ...form.chat, offHoursMessage: e.target.value })} /></Field>
        <SaveBar patch={{ notifications: form.notifications, chat: form.chat }} />
      </section>
    </div>
  );
}
