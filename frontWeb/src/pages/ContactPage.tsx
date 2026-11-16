import { buildWhatsAppLink, formatPhone, normalizeGabonPhone } from '@/shared';
import { Mail, MapPin, MessageCircle, Phone, Send } from 'lucide-react';
import { useState } from 'react';
import { Alert, Field, Seo, Spinner } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { useSettings } from '@/lib/hooks';

export default function ContactPage() {
  const { data: settings } = useSettings();
  const [f, setF] = useState({ name: '', phone: '', email: '', subject: '', message: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [state, setState] = useState<{ loading?: boolean; done?: boolean; error?: string }>({});
  const set = (k: keyof typeof f, v: string) => setF((p) => ({ ...p, [k]: v }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (f.name.trim().length < 2) errs.name = 'Indiquez votre nom';
    if (!normalizeGabonPhone(f.phone)) errs.phone = 'Numéro invalide (+241…)';
    if (f.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.email)) errs.email = 'E-mail invalide';
    if (f.message.trim().length < 10) errs.message = 'Décrivez votre demande (10 caractères min.)';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setState({ loading: true });
    try {
      await api('/contact', { method: 'POST', body: { ...f, email: f.email || undefined, subject: f.subject || undefined } });
      setState({ done: true });
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };

  return (
    <div className="container-page py-8">
      <Seo title="Contact et assistance" description="Contactez l'équipe DutCh’M à Libreville : WhatsApp, téléphone, e-mail ou formulaire." />
      <h1 className="section-title mb-8 !text-3xl">Contact et assistance</h1>
      <div className="grid gap-8 lg:grid-cols-[1fr_380px]">
        <div className="card p-5 sm:p-6">
          {state.done ? (
            <Alert tone="success">
              <p className="font-semibold">Message envoyé !</p>
              <p className="mt-1">Notre équipe vous répondra très vite. Vous pouvez aussi continuer la conversation depuis le widget de discussion en bas à droite.</p>
            </Alert>
          ) : (
            <form onSubmit={submit} className="space-y-4" noValidate>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Votre nom *" error={errors.name}><input className="input" value={f.name} onChange={(e) => set('name', e.target.value)} autoComplete="name" /></Field>
                <Field label="Téléphone *" error={errors.phone}><input className="input" inputMode="tel" value={f.phone} onChange={(e) => set('phone', e.target.value)} placeholder="+241 77 12 34 56" /></Field>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="E-mail (facultatif)" error={errors.email}><input className="input" type="email" value={f.email} onChange={(e) => set('email', e.target.value)} /></Field>
                <Field label="Sujet">
                  <select className="input" value={f.subject} onChange={(e) => set('subject', e.target.value)}>
                    <option value="">Question générale</option>
                    <option>Suivi d'une commande</option>
                    <option>Produit ou disponibilité</option>
                    <option>Précommande et délais</option>
                    <option>Retour ou remboursement</option>
                    <option>Partenariat</option>
                  </select>
                </Field>
              </div>
              <Field label="Votre message *" error={errors.message}>
                <textarea className="input" rows={5} value={f.message} onChange={(e) => set('message', e.target.value)} placeholder="Bonjour DutCh’M, …" />
              </Field>
              {state.error && <Alert tone="error">{state.error}</Alert>}
              <button className="btn-primary" disabled={state.loading}>{state.loading ? <Spinner /> : <><Send className="h-4 w-4" /> Envoyer</>}</button>
            </form>
          )}
        </div>
        <aside className="space-y-4">
          {settings && (
            <div className="card space-y-3 p-5 text-sm">
              <h2 className="font-serif text-xl">Nos coordonnées</h2>
              <p className="flex items-center gap-2"><Phone className="h-4 w-4 text-gold" /> {formatPhone(settings.shop.phone)}</p>
              <p className="flex items-center gap-2"><Mail className="h-4 w-4 text-gold" /> {settings.shop.email}</p>
              <p className="flex items-start gap-2"><MapPin className="mt-0.5 h-4 w-4 text-gold" /> {settings.shop.address}</p>
              <p className="text-xs text-ink-soft">Assistance : {settings.supportHours.label}{settings.supportOnline ? ' · en ligne' : ' · hors ligne'}</p>
              <a className="btn-primary w-full" href={buildWhatsAppLink(settings.whatsappNumber, 'Bonjour DutCh’M !')} target="_blank" rel="noopener noreferrer">
                <MessageCircle className="h-4 w-4" /> Discuter sur WhatsApp
              </a>
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}
