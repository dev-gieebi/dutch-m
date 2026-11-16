import { newsletterSchema } from '@/shared';
import { useState } from 'react';
import { api, errorMessage } from '@/lib/api';
import { Spinner } from './ui';

export function NewsletterForm({ dark = false }: { dark?: boolean }) {
  const [contact, setContact] = useState('');
  const [consent, setConsent] = useState(false);
  const [state, setState] = useState<{ loading?: boolean; message?: string; error?: string }>({});

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const isEmail = contact.includes('@');
    const parsed = newsletterSchema.safeParse({ email: isEmail ? contact : '', phone: isEmail ? undefined : contact, consent });
    if (!parsed.success) return setState({ error: parsed.error.issues[0]?.message });
    setState({ loading: true });
    try {
      const res = await api<{ message: string }>('/newsletter', { method: 'POST', body: { email: parsed.data.email, phone: parsed.data.phone, consent: true } });
      setState({ message: res.message });
      setContact('');
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };

  return (
    <form onSubmit={submit} className="space-y-2" noValidate>
      <label htmlFor={`nl-${dark}`} className="sr-only">E-mail ou téléphone</label>
      <div className="flex gap-2">
        <input id={`nl-${dark}`} className={`input ${dark ? '!border-ivory/20 !bg-ivory/10 !text-ivory' : ''}`} placeholder="E-mail ou téléphone" value={contact} onChange={(e) => setContact(e.target.value)} />
        <button className="btn-primary shrink-0" disabled={state.loading}>{state.loading ? <Spinner /> : 'OK'}</button>
      </div>
      <label className={`flex items-start gap-2 text-xs ${dark ? 'text-ivory/70' : 'text-ink-soft'}`}>
        <input type="checkbox" className="mt-0.5 h-4 w-4 accent-gold" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
        J’accepte de recevoir les actualités de DutCh’M. Désinscription possible à tout moment.
      </label>
      {state.message && <p className="text-xs text-success">{state.message}</p>}
      {state.error && <p className="text-xs text-rose">{state.error}</p>}
    </form>
  );
}
