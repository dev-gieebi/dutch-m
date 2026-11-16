import { AdminNotificationType, formatDate, Permission, RoleCode } from '@/shared';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Bell, Boxes, ChartBar, FileText, Flower2, LayoutDashboard, LogOut, Megaphone, MessageSquare,
  Package, PackageSearch, ScrollText, Settings, ShieldCheck, ShoppingCart, Truck, Users, Warehouse, Menu, X,
} from 'lucide-react';
import { ReactNode, useEffect, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { api } from '@/admin/lib/api';
import { closeAdminSocket, getAdminSocket } from '@/admin/lib/socket';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';
import { Badge, Toasts } from './ui';

interface AdminNotification {
  id: number;
  type: AdminNotificationType;
  title: string;
  link: string | null;
  readAt: string | null;
  createdAt: string;
}

const NAV: { to: string; icon: typeof LayoutDashboard; label: string; perm: Permission | Permission[] }[] = [
  { to: '/admin', icon: LayoutDashboard, label: 'Tableau de bord', perm: Permission.DASHBOARD_READ },
  { to: '/admin/commandes', icon: ShoppingCart, label: 'Commandes', perm: Permission.ORDERS_READ },
  { to: '/admin/catalogue', icon: Package, label: 'Catalogue', perm: Permission.CATALOG_READ },
  { to: '/admin/stocks', icon: Boxes, label: 'Stocks', perm: Permission.STOCK_READ },
  { to: '/admin/precommandes', icon: PackageSearch, label: 'Précommandes', perm: Permission.ORDERS_READ },
  { to: '/admin/arrivages', icon: Warehouse, label: 'Arrivages', perm: Permission.ARRIVALS_WRITE },
  { to: '/admin/livraisons', icon: Truck, label: 'Livraisons', perm: Permission.DELIVERY_WRITE },
  { to: '/admin/livreur', icon: Truck, label: 'Mes livraisons', perm: Permission.DRIVER_DELIVERIES },
  { to: '/admin/clients', icon: Users, label: 'Clients', perm: Permission.CUSTOMERS_READ },
  { to: '/admin/messagerie', icon: MessageSquare, label: 'Messagerie', perm: Permission.MESSAGES_READ },
  { to: '/admin/marketing', icon: Megaphone, label: 'Marketing', perm: Permission.MARKETING_WRITE },
  { to: '/admin/contenus', icon: FileText, label: 'Contenus', perm: Permission.CONTENT_WRITE },
  { to: '/admin/rapports', icon: ChartBar, label: 'Rapports', perm: Permission.REPORTS_READ },
  { to: '/admin/parametres', icon: Settings, label: 'Paramètres', perm: Permission.SETTINGS_WRITE },
  { to: '/admin/utilisateurs', icon: ShieldCheck, label: 'Utilisateurs', perm: Permission.USERS_WRITE },
  { to: '/admin/journal', icon: ScrollText, label: "Journal d'audit", perm: Permission.AUDIT_READ },
];

function NotificationsBell() {
  const qc = useQueryClient();
  const notify = useUiStore((s) => s.notify);
  const [open, setOpen] = useState(false);
  const { data } = useQuery({
    queryKey: ['admin-notifications'],
    queryFn: () => api<{ data: AdminNotification[]; unread: number }>('/admin/notifications', { query: { limit: 15 } }),
    refetchInterval: 60_000,
  });
  useEffect(() => {
    const socket = getAdminSocket();
    const onEvent = (event: string) => (payload: { title?: string }) => {
      qc.invalidateQueries({ queryKey: ['admin-notifications'] });
      qc.invalidateQueries({ queryKey: ['conversations'] });
      if (payload?.title) notify(payload.title, 'info');
    };
    const events = ['order.created', 'payment.updated', 'message.created', 'stock.low', 'notification.created'];
    events.forEach((e) => socket.on(e, onEvent(e)));
    return () => { events.forEach((e) => socket.off(e)); };
  }, [qc, notify]);
  return (
    <div className="relative">
      <button className="btn-ghost relative !px-2.5" onClick={() => setOpen(!open)} aria-label={`Notifications (${data?.unread ?? 0} non lues)`}>
        <Bell className="h-5 w-5" />
        {!!data?.unread && <span className="absolute right-1 top-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-rose px-1 text-[10px] font-bold text-white">{data.unread}</span>}
      </button>
      {open && (
        <div className="absolute right-0 top-12 z-50 w-80 rounded-xl border border-sand bg-white shadow-soft" role="menu">
          <div className="flex items-center justify-between border-b border-sand px-4 py-2">
            <p className="text-sm font-semibold">Notifications</p>
            <button className="text-xs text-gold-dark hover:underline" onClick={() => api('/admin/notifications/read-all', { method: 'POST' }).then(() => qc.invalidateQueries({ queryKey: ['admin-notifications'] }))}>
              Tout marquer lu
            </button>
          </div>
          <ul className="max-h-80 overflow-y-auto">
            {data?.data.length ? data.data.map((n) => (
              <li key={n.id}>
                <button
                  className={`flex w-full items-start gap-2 px-4 py-3 text-left text-sm hover:bg-ivory ${n.readAt ? 'opacity-60' : ''}`}
                  onClick={() => { void api(`/admin/notifications/${n.id}/read`, { method: 'POST' }).then(() => qc.invalidateQueries({ queryKey: ['admin-notifications'] })); setOpen(false); }}
                >
                  <span className="flex-1">{n.title}</span>
                  <span className="text-[10px] text-ink-soft">{formatDate(n.createdAt, true)}</span>
                </button>
              </li>
            )) : <li className="px-4 py-6 text-center text-sm text-ink-soft">Aucune notification</li>}
          </ul>
        </div>
      )}
    </div>
  );
}

export function AdminLayout({ children }: { children: ReactNode }) {
  const user = useAuthStore((s) => s.user);
  const clear = useAuthStore((s) => s.clear);
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const [menuOpen, setMenuOpen] = useState(false);
  const visible = NAV.filter((n) => hasPermission(user, n.perm));

  const logout = async () => {
    await api('/admin/auth/logout', { method: 'POST' }).catch(() => undefined);
    closeAdminSocket();
    clear();
    navigate('/compte/connexion');
  };

  const nav = (
    <nav className="flex-1 space-y-0.5 overflow-y-auto p-3" aria-label="Menu principal">
      {visible.map(({ to, icon: Icon, label }) => (
        <NavLink
          key={to}
          to={to}
          end={to === '/admin'}
          onClick={() => setMenuOpen(false)}
          className={({ isActive }) => `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm ${isActive ? 'bg-gold/20 font-semibold text-gold-light' : 'text-ivory/70 hover:bg-white/5 hover:text-ivory'}`}
        >
          <Icon className="h-4 w-4 shrink-0" /> {label}
        </NavLink>
      ))}
    </nav>
  );

  return (
    <div className="flex min-h-screen">
      <aside className="fixed inset-y-0 left-0 z-40 hidden w-60 flex-col bg-ink md:flex">
        <Link to="/admin" className="flex flex-col items-start px-5 py-5">
          <span className="font-serif text-2xl font-bold tracking-[0.18em] text-gold-light">DutCh’M</span>
          <span className="text-[9px] uppercase tracking-[0.25em] text-ivory/50">Administration</span>
        </Link>
        {nav}
        <div className="border-t border-white/10 p-3 text-xs text-ivory/60">
          <p className="font-medium text-ivory">{user?.fullName}</p>
          <p className="mb-2">{user?.roles.map((r) => (RoleCode as Record<string, string>)[r] ?? r).join(', ')}</p>
          <Link to="/admin/mon-profil" className="text-gold-light hover:underline">Mon profil</Link>
          <button onClick={logout} className="mt-1 flex items-center gap-1 text-ivory/70 hover:text-ivory"><LogOut className="h-3.5 w-3.5" /> Déconnexion</button>
        </div>
      </aside>
      <div className="flex min-h-screen flex-1 flex-col md:pl-60">
        <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-sand bg-ivory/95 px-4 py-2.5 backdrop-blur">
          <button className="btn-ghost !px-2 md:hidden" onClick={() => setMenuOpen(true)} aria-label="Ouvrir le menu"><Menu className="h-5 w-5" /></button>
          <p className="text-sm font-semibold capitalize">{pathname === '/admin' ? 'Tableau de bord' : (pathname.split('/')[2] ?? '').replace(/-/g, ' ')}</p>
          <div className="ml-auto flex items-center gap-1">
            {user && <Badge tone="gold">{user.roles.join(' · ')}</Badge>}
            <NotificationsBell />
          </div>
        </header>
        <main className="flex-1 p-4 sm:p-6">{children}</main>
      </div>
      {menuOpen && (
        <div className="fixed inset-0 z-50 flex bg-ink/50 md:hidden" onClick={() => setMenuOpen(false)}>
          <aside className="flex h-full w-64 flex-col bg-ink" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between px-5 py-4">
              <span className="font-serif text-xl font-bold tracking-[0.18em] text-gold-light">DutCh’M</span>
              <button onClick={() => setMenuOpen(false)} className="p-2 text-ivory" aria-label="Fermer"><X className="h-5 w-5" /></button>
            </div>
            {nav}
            <button onClick={logout} className="m-3 flex items-center gap-2 rounded-lg px-3 py-2.5 text-sm text-ivory/70"><LogOut className="h-4 w-4" /> Déconnexion</button>
          </aside>
        </div>
      )}
      <Toasts />
    </div>
  );
}
