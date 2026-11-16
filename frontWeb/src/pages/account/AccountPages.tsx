import {
  DeliveryZoneDto,
  formatDate,
  formatFCFA,
  formatPhone,
  ItemType,
  ORDER_STATUS_LABELS,
  OrderStatus,
  OrderTrackingDto,
  PAYMENT_STATUS_LABELS,
  ProductCardDto,
} from '@/shared';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Heart, Home, LogOut, MapPin, Package, PackageOpen, Pencil, Plus, Trash2, UserCircle2 } from 'lucide-react';
import { useState } from 'react';
import { Link, Navigate, Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { ProductGrid } from '@/components/ProductCard';
import { Alert, EmptyState, Field, Modal, PageSkeleton, Seo, Skeleton, Spinner } from '@/components/ui';
import { api, assetUrl, errorMessage } from '@/lib/api';
import { useZones } from '@/lib/hooks';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';

interface MyOrder {
  id: number; number: string; createdAt: string; status: OrderStatus; paymentStatus: string;
  total: number; balanceDue: number; orderType: string; trackingToken: string;
}
interface MyOrderDetail extends OrderTrackingDto {
  trackingToken: string;
  items: { id: number; productName: string; variantName: string | null; imageUrl: string | null; unitPrice: number; quantity: number; lineTotal: number; itemType: ItemType }[];
  contact: { name: string; phone: string; email: string | null };
  address: { city: string | null; zone: string | null; district: string | null; landmark: string | null; instructions: string | null };
}
interface Address {
  id: number; label: string | null; city: string; zoneId: number | null; zoneName: string | null;
  district: string | null; landmark: string; instructions: string | null; isDefault: boolean;
}

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { accessToken, hasSession } = useAuthStore();
  if (!accessToken && hasSession) return <PageSkeleton />;
  if (!accessToken) return <Navigate to="/compte/connexion?redirect=/mon-compte" replace />;
  return <>{children}</>;
}

function Nav() {
  const navigate = useNavigate();
  const clear = useAuthStore((s) => s.clear);
  const logout = async () => {
    await api('/auth/customer/logout', { method: 'POST' }).catch(() => undefined);
    clear();
    navigate('/');
  };
  const links = [
    ['/mon-compte', Package, 'Commandes'],
    ['/mon-compte/adresses', MapPin, 'Adresses'],
    ['/mon-compte/favoris', Heart, 'Favoris'],
    ['/mon-compte/profil', UserCircle2, 'Profil'],
  ] as const;
  return (
    <nav className="mb-6 flex flex-wrap gap-2" aria-label="Mon compte">
      {links.map(([to, Icon, label]) => (
        <Link key={to} to={to} className="badge min-h-[40px] bg-white px-4 shadow-soft hover:bg-gold/10"><Icon className="h-4 w-4 text-gold" /> {label}</Link>
      ))}
      <button onClick={logout} className="badge min-h-[40px] bg-white px-4 text-danger shadow-soft"><LogOut className="h-4 w-4" /> Déconnexion</button>
    </nav>
  );
}

function Orders() {
  const { data, isLoading } = useQuery({ queryKey: ['my-orders'], queryFn: () => api<MyOrder[]>('/me/orders') });
  if (isLoading) return <Skeleton className="h-64" />;
  if (!data?.length)
    return <EmptyState icon={<PackageOpen className="h-10 w-10" />} title="Aucune commande" text="Vos commandes apparaîtront ici." action={<Link to="/boutique" className="btn-primary">Découvrir la boutique</Link>} />;
  return (
    <ul className="space-y-3">
      {data.map((o) => (
        <li key={o.id}>
          <Link to={`/mon-compte/commandes/${o.number}`} className="card flex flex-wrap items-center justify-between gap-3 p-4 transition hover:-translate-y-0.5">
            <div>
              <p className="font-mono text-sm font-semibold">{o.number}</p>
              <p className="text-xs text-ink-soft">{formatDate(o.createdAt, true)} · {o.orderType === 'MIXED' ? 'Stock + précommande' : o.orderType === 'PREORDER' ? 'Précommande' : 'Stock local'}</p>
            </div>
            <div className="flex items-center gap-3">
              <span className="badge bg-sand">{ORDER_STATUS_LABELS[o.status]}</span>
              <span className="font-semibold text-gold-dark">{formatFCFA(o.total)}</span>
            </div>
          </Link>
        </li>
      ))}
    </ul>
  );
}

function OrderDetail() {
  const { number = '' } = useParams();
  const { data, isLoading, isError } = useQuery({ queryKey: ['my-order', number], queryFn: () => api<MyOrderDetail>(`/me/orders/${number}`), retry: false });
  if (isLoading) return <Skeleton className="h-80" />;
  if (isError || !data) return <Alert tone="error">Commande introuvable.</Alert>;
  return (
    <div className="space-y-6">
      <div className="card flex flex-wrap items-center justify-between gap-3 p-5">
        <div>
          <h2 className="font-mono font-semibold">{data.number}</h2>
          <p className="text-xs text-ink-soft">{formatDate(data.createdAt, true)}</p>
        </div>
        <div className="flex gap-2 text-xs">
          <span className="badge bg-sand px-3 py-1.5">{ORDER_STATUS_LABELS[data.status]}</span>
          <span className="badge bg-gold/15 px-3 py-1.5 text-gold-dark">{PAYMENT_STATUS_LABELS[data.paymentStatus]}</span>
        </div>
      </div>
      <div className="card p-5">
        <h3 className="mb-3 font-semibold">Articles</h3>
        <ul className="divide-y divide-sand text-sm">
          {data.items.map((i) => (
            <li key={i.id} className="flex items-center gap-3 py-2">
              <span className="h-12 w-12 overflow-hidden rounded-lg bg-sand">{i.imageUrl && <img src={assetUrl(i.imageUrl)} alt="" className="h-full w-full object-cover" loading="lazy" />}</span>
              <span className="flex-1">{i.productName} {i.variantName ? `· ${i.variantName}` : ''} <span className="text-xs text-ink-soft">× {i.quantity}{i.itemType === ItemType.PREORDER ? ' · précommande' : ''}</span></span>
              <span className="font-medium">{formatFCFA(i.lineTotal)}</span>
            </li>
          ))}
        </ul>
        <dl className="mt-3 space-y-1 border-t border-sand pt-3 text-sm">
          <div className="flex justify-between"><dt>Sous-total</dt><dd>{formatFCFA(data.subtotal)}</dd></div>
          {data.discountTotal > 0 && <div className="flex justify-between text-success"><dt>Remise</dt><dd>-{formatFCFA(data.discountTotal)}</dd></div>}
          <div className="flex justify-between"><dt>Livraison</dt><dd>{formatFCFA(data.shippingTotal)}</dd></div>
          <div className="flex justify-between font-semibold"><dt>Total</dt><dd className="text-gold-dark">{formatFCFA(data.total)}</dd></div>
          {data.balanceDue > 0 && <div className="flex justify-between text-warning"><dt>Reste à payer</dt><dd>{formatFCFA(data.balanceDue)}</dd></div>}
        </dl>
      </div>
      {data.address.landmark && (
        <div className="card p-5 text-sm">
          <h3 className="mb-2 font-semibold">Livraison</h3>
          <p>{[data.address.district, data.address.zone, data.address.city].filter(Boolean).join(', ')}</p>
          <p className="text-ink-soft">Repère : {data.address.landmark}</p>
        </div>
      )}
      <Link to={`/suivi/${data.trackingToken}`} className="btn-primary w-full sm:w-auto">Voir le suivi détaillé</Link>
    </div>
  );
}

const emptyAddr = { label: '', city: 'Libreville', zoneId: '', district: '', landmark: '', instructions: '', isDefault: false };

function Addresses() {
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const { data: zones } = useZones();
  const { data, isLoading } = useQuery({ queryKey: ['my-addresses'], queryFn: () => api<Address[]>('/me/addresses') });
  const [editing, setEditing] = useState<Address | null>(null);
  const [open, setOpen] = useState(false);
  const [f, setF] = useState({ ...emptyAddr });
  const [error, setError] = useState<string | null>(null);

  const save = useMutation({
    mutationFn: () => {
      const body = { ...f, label: f.label || null, zoneId: f.zoneId ? Number(f.zoneId) : null, district: f.district || null, landmark: f.landmark, instructions: f.instructions || null };
      return editing ? api(`/me/addresses/${editing.id}`, { method: 'PATCH', body }) : api('/me/addresses', { method: 'POST', body });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['my-addresses'] });
      setOpen(false);
      notify(editing ? 'Adresse modifiée' : 'Adresse ajoutée');
    },
    onError: (e) => setError(errorMessage(e)),
  });
  const remove = useMutation({
    mutationFn: (id: number) => api(`/me/addresses/${id}`, { method: 'DELETE' }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['my-addresses'] }); notify('Adresse supprimée'); },
    onError: (e) => notify(errorMessage(e), 'error'),
  });

  const openForm = (a?: Address) => {
    setEditing(a ?? null);
    setF(a ? { label: a.label ?? '', city: a.city, zoneId: a.zoneId ? String(a.zoneId) : '', district: a.district ?? '', landmark: a.landmark, instructions: a.instructions ?? '', isDefault: a.isDefault } : { ...emptyAddr });
    setError(null);
    setOpen(true);
  };

  if (isLoading) return <Skeleton className="h-48" />;
  return (
    <div>
      <button className="btn-secondary mb-4" onClick={() => openForm()}><Plus className="h-4 w-4" /> Ajouter une adresse</button>
      {data?.length ? (
        <ul className="grid gap-3 sm:grid-cols-2">
          {data.map((a) => (
            <li key={a.id} className="card p-4 text-sm">
              <div className="mb-1 flex items-center justify-between">
                <p className="font-semibold">{a.label ?? 'Adresse'}{a.isDefault && <span className="badge ml-2 bg-gold/15 text-gold-dark">Par défaut</span>}</p>
                <div className="flex gap-1">
                  <button onClick={() => openForm(a)} className="p-2 text-ink-soft hover:text-gold-dark" aria-label="Modifier"><Pencil className="h-4 w-4" /></button>
                  <button onClick={() => remove.mutate(a.id)} className="p-2 text-ink-soft hover:text-danger" aria-label="Supprimer"><Trash2 className="h-4 w-4" /></button>
                </div>
              </div>
              <p>{[a.district, a.zoneName, a.city].filter(Boolean).join(', ')}</p>
              <p className="text-xs text-ink-soft">Repère : {a.landmark}</p>
            </li>
          ))}
        </ul>
      ) : (
        <EmptyState icon={<Home className="h-10 w-10" />} title="Aucune adresse" text="Enregistrez vos adresses pour commander plus vite." />
      )}
      <Modal open={open} onClose={() => setOpen(false)} title={editing ? 'Modifier l’adresse' : 'Nouvelle adresse'}>
        <form onSubmit={(e) => { e.preventDefault(); save.mutate(); }} className="space-y-3">
          <Field label="Nom de l'adresse"><input className="input" value={f.label} onChange={(e) => setF({ ...f, label: e.target.value })} placeholder="Domicile, bureau…" /></Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Ville *"><input className="input" value={f.city} onChange={(e) => setF({ ...f, city: e.target.value })} required /></Field>
            <Field label="Zone"><select className="input" value={f.zoneId} onChange={(e) => setF({ ...f, zoneId: e.target.value })}><option value="">—</option>{zones?.map((z: DeliveryZoneDto) => <option key={z.id} value={z.id}>{z.name}</option>)}</select></Field>
          </div>
          <Field label="Quartier"><input className="input" value={f.district} onChange={(e) => setF({ ...f, district: e.target.value })} /></Field>
          <Field label="Point de repère *"><input className="input" value={f.landmark} onChange={(e) => setF({ ...f, landmark: e.target.value })} required /></Field>
          <Field label="Instructions"><input className="input" value={f.instructions} onChange={(e) => setF({ ...f, instructions: e.target.value })} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={f.isDefault} onChange={(e) => setF({ ...f, isDefault: e.target.checked })} /> Définir comme adresse par défaut</label>
          {error && <Alert tone="error">{error}</Alert>}
          <button className="btn-primary w-full" disabled={save.isPending}>{save.isPending ? <Spinner /> : 'Enregistrer'}</button>
        </form>
      </Modal>
    </div>
  );
}

function Wishlist() {
  const { data, isLoading } = useQuery({ queryKey: ['my-wishlist'], queryFn: () => api<ProductCardDto[]>('/me/wishlist') });
  if (isLoading) return <Skeleton className="h-64" />;
  if (!data?.length)
    return <EmptyState icon={<Heart className="h-10 w-10" />} title="Aucun favori" text="Touchez le cœur sur une fiche produit pour la retrouver ici." action={<Link to="/boutique" className="btn-primary">Découvrir la boutique</Link>} />;
  return <ProductGrid products={data} />;
}

function Profile() {
  const { customer, setCustomer, clear } = useAuthStore();
  const notify = useUiStore((s) => s.notify);
  const navigate = useNavigate();
  const [f, setF] = useState({ fullName: customer?.fullName ?? '', email: customer?.email ?? '', newsletterConsent: customer?.newsletterConsent ?? false });
  const [pw, setPw] = useState({ currentPassword: '', newPassword: '' });
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deletePassword, setDeletePassword] = useState('');
  const [state, setState] = useState<{ loading?: boolean; error?: string }>({});

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setState({ loading: true });
    try {
      const updated = await api<typeof customer>('/me', {
        method: 'PATCH',
        body: {
          fullName: f.fullName.trim(),
          email: f.email || null,
          newsletterConsent: f.newsletterConsent,
          ...(pw.newPassword ? { currentPassword: pw.currentPassword, newPassword: pw.newPassword } : {}),
        },
      });
      if (updated) setCustomer(updated);
      notify('Profil mis à jour');
      setPw({ currentPassword: '', newPassword: '' });
      setState({});
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };
  const deleteAccount = async () => {
    try {
      await api('/me', { method: 'DELETE', body: { password: deletePassword } });
      clear();
      notify('Compte supprimé. Vos données ont été anonymisées.');
      navigate('/');
    } catch (err) {
      setState({ error: errorMessage(err) });
    }
  };

  return (
    <div className="max-w-xl space-y-6">
      <form onSubmit={save} className="card space-y-4 p-5">
        <h2 className="font-serif text-xl">Mes informations</h2>
        <Field label="Nom complet"><input className="input" value={f.fullName} onChange={(e) => setF({ ...f, fullName: e.target.value })} /></Field>
        <Field label="Téléphone" hint="Le téléphone ne peut pas être modifié : il identifie vos commandes."><input className="input" value={formatPhone(customer?.phone)} disabled /></Field>
        <Field label="E-mail"><input className="input" type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={f.newsletterConsent} onChange={(e) => setF({ ...f, newsletterConsent: e.target.checked })} /> Recevoir la newsletter DutCh’M</label>
        <h3 className="pt-2 font-semibold">Changer le mot de passe</h3>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Actuel"><input className="input" type="password" value={pw.currentPassword} onChange={(e) => setPw({ ...pw, currentPassword: e.target.value })} autoComplete="current-password" /></Field>
          <Field label="Nouveau (8 caractères min.)"><input className="input" type="password" value={pw.newPassword} onChange={(e) => setPw({ ...pw, newPassword: e.target.value })} autoComplete="new-password" /></Field>
        </div>
        {state.error && <Alert tone="error">{state.error}</Alert>}
        <button className="btn-primary" disabled={state.loading}>{state.loading ? <Spinner /> : 'Enregistrer'}</button>
      </form>
      <div className="card border-danger/30 p-5">
        <h2 className="mb-2 font-serif text-xl text-danger">Zone sensible</h2>
        <p className="mb-3 text-sm text-ink-soft">La suppression anonymise vos données personnelles. L'historique comptable des commandes est conservé sans lien avec votre identité.</p>
        <button className="btn-ghost !text-danger" onClick={() => setDeleteOpen(true)}><Trash2 className="h-4 w-4" /> Supprimer mon compte</button>
      </div>
      <Modal open={deleteOpen} onClose={() => setDeleteOpen(false)} title="Supprimer mon compte">
        <form onSubmit={(e) => { e.preventDefault(); deleteAccount(); }} className="space-y-4">
          <p className="text-sm text-ink-soft">Cette action est définitive. Confirmez avec votre mot de passe.</p>
          <Field label="Mot de passe"><input className="input" type="password" value={deletePassword} onChange={(e) => setDeletePassword(e.target.value)} required /></Field>
          <button className="btn-primary w-full !bg-none !bg-danger">Supprimer définitivement</button>
        </form>
      </Modal>
    </div>
  );
}

export default function AccountPages() {
  return (
    <RequireAuth>
      <div className="container-page py-8">
        <Seo title="Mon compte" noindex />
        <Nav />
        <Routes>
          <Route index element={<Orders />} />
          <Route path="commandes/:number" element={<OrderDetail />} />
          <Route path="adresses" element={<Addresses />} />
          <Route path="favoris" element={<Wishlist />} />
          <Route path="profil" element={<Profile />} />
          <Route path="*" element={<Navigate to="/mon-compte" replace />} />
        </Routes>
      </div>
    </RequireAuth>
  );
}
