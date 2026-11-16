import { AdminProfile, CustomerProfile, normalizeGabonPhone } from '@/shared';
import { Flower2, KeyRound, MailCheck, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Link, Route, Routes, useNavigate, useSearchParams } from 'react-router-dom';
import { useAuthStore as useAdminAuthStore } from '@/admin/store/auth';
import { Alert, Field, Seo, Spinner } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';
import { useAuthStore } from '@/store/auth';

type Session = { accessToken: string; customer: CustomerProfile };
type AdminLoginResult =
  | { step: 'DONE'; accessToken: string; user: AdminProfile }
  | { step: '2FA' | '2FA_SETUP'; tempToken: string };

function Shell({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) {
  return (
    <div className="container-page max-w-md py-10">
      <div className="card p-6 sm:p-8">
        <div className="mb-6 text-center">
          <Flower2 className="mx-auto mb-2 h-10 w-10 text-rose" />
          <h1 className="font-serif text-2xl font-semibold">{title}</h1>
          <p className="mt-1 text-sm text-ink-soft">{subtitle}</p>
        </div>
        {children}
      </div>
    </div>
  );
}

function Login() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const setSession = useAuthStore((s) => s.setSession);
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [state, setState] = useState<{ loading?: boolean; error?: string }>({});
  const [admin2fa, setAdmin2fa] = useState<{ tempToken: string; qr?: string; secret?: string } | null>(null);
  const [code, setCode] = useState('');

  /** Session admin ouverte → direction le back-office intégré (/admin). */
  const handoff = (r: { accessToken: string; user: AdminProfile }) => {
    useAdminAuthStore.getState().setSession(r.accessToken, r.user);
    navigate('/admin');
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setState({ loading: true });
    let customerError: unknown;
    try {
      const res = await api<Session>('/auth/customer/login', { method: 'POST', body: { identifier, password } });
      setSession(res.accessToken, res.customer);
      navigate(params.get('redirect') ?? '/mon-compte');
      return;
    } catch (err) {
      customerError = err;
    }
    // Pas un compte client → essai côté administration (e-mail uniquement).
    if (identifier.includes('@')) {
      try {
        const a = await api<AdminLoginResult>('/admin/auth/login', { method: 'POST', body: { email: identifier.trim().toLowerCase(), password } });
        if (a.step === 'DONE') return handoff(a);
        if (a.step === '2FA_SETUP') {
          const s = await api<{ secret: string; qrCodeDataUrl: string }>('/admin/auth/2fa/setup', { method: 'POST', body: { tempToken: a.tempToken } });
          setAdmin2fa({ tempToken: a.tempToken, qr: s.qrCodeDataUrl, secret: s.secret });
        } else {
          setAdmin2fa({ tempToken: a.tempToken });
        }
        return setState({});
      } catch { /* identifiants invalides des deux côtés */ }
    }
    setState({ error: errorMessage(customerError) });
  };

  const submitCode = async (e: React.FormEvent) => {
    e.preventDefault();
    setState({ loading: true });
    try {
      const res = await api<AdminLoginResult>('/admin/auth/2fa/verify', { method: 'POST', body: { tempToken: admin2fa!.tempToken, code } });
      if (res.step === 'DONE') handoff(res);
      else setState({ error: 'Code incorrect' });
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };

  if (admin2fa) {
    return (
      <Shell title="Espace administration" subtitle={admin2fa.qr ? "Activez la double authentification pour sécuriser l'accès" : 'Double authentification'}>
        <form onSubmit={submitCode} className="space-y-4">
          <div className="text-center">
            <ShieldCheck className="mx-auto mb-2 h-8 w-8 text-gold" />
            <p className="text-sm text-ink-soft">{admin2fa.qr ? "Scannez le code QR avec votre application (Google Authenticator, Microsoft Authenticator…) puis saisissez le code généré." : 'Saisissez le code à 6 chiffres de votre application.'}</p>
          </div>
          {admin2fa.qr && (
            <div className="flex flex-col items-center gap-3 rounded-lg bg-ivory p-4">
              <img src={admin2fa.qr} alt="QR code TOTP" className="h-44 w-44" />
              <p className="break-all text-center font-mono text-xs text-ink-soft">{admin2fa.secret}</p>
            </div>
          )}
          <Field label="Code à 6 chiffres">
            <input className="input text-center font-mono text-xl tracking-[0.5em]" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} autoComplete="one-time-code" required autoFocus />
          </Field>
          {state.error && <Alert tone="error">{state.error}</Alert>}
          <button className="btn-primary w-full" disabled={state.loading || code.length !== 6}>{state.loading ? <Spinner /> : <><KeyRound className="h-4 w-4" /> Valider</>}</button>
          <button type="button" className="btn-ghost w-full !min-h-[32px] text-xs" onClick={() => { setAdmin2fa(null); setCode(''); setState({}); }}>← Retour à la connexion</button>
        </form>
      </Shell>
    );
  }

  return (
    <Shell title="Connexion" subtitle="Retrouvez vos commandes, adresses et favoris">
      <form onSubmit={submit} className="space-y-4">
        <Field label="E-mail ou téléphone">
          <input className="input" value={identifier} onChange={(e) => setIdentifier(e.target.value)} autoComplete="username" placeholder="+241… ou vous@exemple.com" required />
        </Field>
        <Field label="Mot de passe">
          <input className="input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required />
        </Field>
        {state.error && <Alert tone="error">{state.error}</Alert>}
        <button className="btn-primary w-full" disabled={state.loading}>{state.loading ? <Spinner /> : 'Se connecter'}</button>
        <div className="flex justify-between text-sm">
          <Link to="/compte/mot-de-passe-oublie" className="text-gold-dark hover:underline">Mot de passe oublié ?</Link>
          <Link to={`/compte/inscription${params.get('redirect') ? `?redirect=${params.get('redirect')}` : ''}`} className="text-gold-dark hover:underline">Créer un compte</Link>
        </div>
      </form>
    </Shell>
  );
}

function Register() {
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const setSession = useAuthStore((s) => s.setSession);
  const [f, setF] = useState({ fullName: '', phone: '', email: '', password: '', newsletterConsent: true });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [state, setState] = useState<{ loading?: boolean; error?: string }>({});
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const errs: Record<string, string> = {};
    if (f.fullName.trim().length < 2) errs.fullName = 'Indiquez votre nom complet';
    if (!normalizeGabonPhone(f.phone)) errs.phone = 'Numéro invalide (+241…)';
    if (f.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.email)) errs.email = 'E-mail invalide';
    if (f.password.length < 8) errs.password = '8 caractères minimum';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setState({ loading: true });
    try {
      const res = await api<Session>('/auth/customer/register', {
        method: 'POST',
        body: { fullName: f.fullName.trim(), phone: normalizeGabonPhone(f.phone), email: f.email || undefined, password: f.password, newsletterConsent: f.newsletterConsent },
      });
      setSession(res.accessToken, res.customer);
      navigate(params.get('redirect') ?? '/mon-compte');
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };
  return (
    <Shell title="Créer mon compte" subtitle="Vos commandes passées avec ce numéro seront rattachées automatiquement">
      <form onSubmit={submit} className="space-y-4" noValidate>
        <Field label="Nom complet *" error={errors.fullName}><input className="input" value={f.fullName} onChange={(e) => setF({ ...f, fullName: e.target.value })} autoComplete="name" /></Field>
        <Field label="Téléphone *" error={errors.phone}><input className="input" inputMode="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} placeholder="+241 77 12 34 56" autoComplete="tel" /></Field>
        <Field label="E-mail (facultatif)" error={errors.email}><input className="input" type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} autoComplete="email" /></Field>
        <Field label="Mot de passe *" error={errors.password}><input className="input" type="password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} autoComplete="new-password" /></Field>
        <label className="flex items-start gap-3 text-xs text-ink-soft">
          <input type="checkbox" className="mt-0.5 h-4 w-4 accent-gold" checked={f.newsletterConsent} onChange={(e) => setF({ ...f, newsletterConsent: e.target.checked })} />
          Je souhaite recevoir les nouveautés et offres DutCh’M (facultatif, désinscription à tout moment).
        </label>
        {state.error && <Alert tone="error">{state.error}</Alert>}
        <button className="btn-primary w-full" disabled={state.loading}>{state.loading ? <Spinner /> : 'Créer mon compte'}</button>
        <p className="text-center text-sm">Déjà inscrite ? <Link to="/compte/connexion" className="text-gold-dark hover:underline">Se connecter</Link></p>
      </form>
    </Shell>
  );
}

function Forgot() {
  const [identifier, setIdentifier] = useState('');
  const [state, setState] = useState<{ loading?: boolean; done?: boolean; error?: string }>({});
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setState({ loading: true });
    try {
      await api('/auth/customer/forgot-password', { method: 'POST', body: { identifier } });
      setState({ done: true });
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };
  return (
    <Shell title="Mot de passe oublié" subtitle="Recevez un lien de réinitialisation par e-mail ou SMS">
      {state.done ? (
        <Alert tone="success"><MailCheck className="mr-1 inline h-4 w-4" /> Si un compte correspond, un lien de réinitialisation vient de vous être envoyé.</Alert>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          <Field label="E-mail ou téléphone"><input className="input" value={identifier} onChange={(e) => setIdentifier(e.target.value)} required /></Field>
          {state.error && <Alert tone="error">{state.error}</Alert>}
          <button className="btn-primary w-full" disabled={state.loading}>{state.loading ? <Spinner /> : 'Envoyer le lien'}</button>
          <p className="text-center text-sm"><Link to="/compte/connexion" className="text-gold-dark hover:underline">Retour à la connexion</Link></p>
        </form>
      )}
    </Shell>
  );
}

function Reset() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const navigate = useNavigate();
  const [password, setPassword] = useState('');
  const [state, setState] = useState<{ loading?: boolean; error?: string }>({});
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password.length < 8) return setState({ error: '8 caractères minimum' });
    setState({ loading: true });
    try {
      await api('/auth/customer/reset-password', { method: 'POST', body: { token, password } });
      navigate('/compte/connexion');
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };
  return (
    <Shell title="Nouveau mot de passe" subtitle="Choisissez un mot de passe de 8 caractères minimum">
      <form onSubmit={submit} className="space-y-4">
        <Field label="Nouveau mot de passe"><input className="input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" required /></Field>
        {state.error && <Alert tone="error">{state.error}</Alert>}
        <button className="btn-primary w-full" disabled={state.loading}>{state.loading ? <Spinner /> : <><KeyRound className="h-4 w-4" /> Modifier</>}</button>
      </form>
    </Shell>
  );
}

export default function AuthPages() {
  return (
    <>
      <Seo title="Mon compte" noindex />
      <Routes>
        <Route path="connexion" element={<Login />} />
        <Route path="inscription" element={<Register />} />
        <Route path="mot-de-passe-oublie" element={<Forgot />} />
        <Route path="reinitialiser" element={<Reset />} />
        <Route path="*" element={<Login />} />
      </Routes>
    </>
  );
}
