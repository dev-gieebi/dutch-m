import { formatFCFA, formatDate, ORDER_STATUS_LABELS, OrderStatus, PAYMENT_STATUS_LABELS, PaymentStatus, PAYMENT_METHOD_LABELS, PaymentMethod } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, Banknote, BellRing, Clock, MessageSquare, PackageSearch, ShoppingCart, Truck, Warehouse } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Badge, EmptyState, PageLoader } from '@/admin/components/ui';
import { api } from '@/admin/lib/api';
import { themeColor } from '@/lib/theme';

interface DashboardData {
  period: string;
  kpis: { revenue: number; orders: number; averageBasket: number; orderedAmount: number };
  counters: {
    ordersToProcess: number; pendingPayments: number; awaitingCash: number; awaitingCashAmount: number;
    delayedShipments: number; lowStock: number; outOfStock: number; unreadMessages: number; arrivalsThisWeek: number;
  };
  charts: {
    salesByDay: { day: string; amount: number; count: number }[];
    salesByCategory: { name: string; amount: number }[];
    topProducts: { name: string; quantity: number; amount: number }[];
    byPaymentMethod: { method: PaymentMethod; count: number; amount: number }[];
  };
  latestOrders: { id: number; number: string; contactName: string; total: number; status: OrderStatus; paymentStatus: PaymentStatus; paymentMethod: PaymentMethod; createdAt: string }[];
  latestMessages: { id: number; visitorName: string; status: string; unread: number; lastMessageAt: string; lastMessage: string | null }[];
}

const COLOR_VARS = ['gold', 'gold-dark', 'rose', 'success', 'warning', 'ink-soft'];
const PERIODS = [['today', "Aujourd'hui"], ['7d', '7 jours'], ['30d', '30 jours']] as const;

export default function Dashboard() {
  const [period, setPeriod] = useState<'today' | '7d' | '30d'>('today');
  const { data, isLoading } = useQuery({ queryKey: ['dashboard', period], queryFn: () => api<DashboardData>('/admin/dashboard', { query: { period } }), refetchInterval: 60_000 });
  if (isLoading || !data) return <PageLoader />;
  const { kpis, counters, charts } = data;
  // Couleurs du thème courant (les attributs SVG n'acceptent pas les variables CSS).
  const COLORS = COLOR_VARS.map(themeColor);
  const cards = [
    { label: "Chiffre d'affaires encaissé", value: formatFCFA(kpis.revenue), icon: Banknote },
    { label: 'Commandes', value: String(kpis.orders), icon: ShoppingCart },
    { label: 'Panier moyen', value: formatFCFA(kpis.averageBasket), icon: ShoppingCart },
  ];
  const countersList = [
    { label: 'À traiter', value: counters.ordersToProcess, to: '/admin/commandes?preset=todo', icon: ShoppingCart, tone: 'text-gold-dark' },
    { label: 'Paiements en attente', value: counters.pendingPayments, to: '/admin/rapports?tab=paiements&status=PENDING', icon: Clock, tone: 'text-warning' },
    { label: 'Encaissements en attente', value: counters.awaitingCash, to: '/admin/commandes?preset=awaiting_cash', icon: Banknote, tone: 'text-warning', suffix: formatFCFA(counters.awaitingCashAmount) },
    { label: 'Expéditions en retard', value: counters.delayedShipments, to: '/admin/commandes?preset=delayed', icon: AlertTriangle, tone: 'text-danger' },
    { label: 'Stocks faibles / ruptures', value: `${counters.lowStock} / ${counters.outOfStock}`, to: '/admin/stocks?lowStock=true', icon: Warehouse, tone: 'text-danger' },
    { label: 'Messages non lus', value: counters.unreadMessages, to: '/admin/messagerie', icon: MessageSquare, tone: 'text-rose-dark' },
    { label: 'Arrivages attendus (7 j)', value: counters.arrivalsThisWeek, to: '/admin/arrivages', icon: Truck, tone: 'text-gold-dark' },
  ];
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Tableau de bord</h1>
        <div className="flex gap-1 rounded-lg bg-sand/60 p-1" role="tablist">
          {PERIODS.map(([v, l]) => (
            <button key={v} role="tab" aria-selected={period === v} onClick={() => setPeriod(v)} className={`rounded-md px-3 py-1.5 text-xs font-semibold ${period === v ? 'bg-white text-gold-dark shadow-soft' : 'text-ink-soft'}`}>{l}</button>
          ))}
        </div>
      </div>
      <div className="grid gap-4 sm:grid-cols-3">
        {cards.map(({ label, value, icon: Icon }) => (
          <div key={label} className="card flex items-center gap-4 p-5">
            <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gold/15 text-gold-dark"><Icon className="h-5 w-5" /></span>
            <div><p className="text-xs uppercase tracking-wide text-ink-soft">{label}</p><p className="text-xl font-bold">{value}</p></div>
          </div>
        ))}
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {countersList.map(({ label, value, to, icon: Icon, tone, suffix }) => (
          <Link key={label} to={to} className="card flex items-center gap-3 p-4 transition hover:-translate-y-0.5">
            <Icon className={`h-5 w-5 ${tone}`} />
            <div className="min-w-0">
              <p className="truncate text-xs text-ink-soft">{label}</p>
              <p className="font-semibold">{value}{suffix ? <span className="ml-1 text-xs font-normal text-ink-soft">{suffix}</span> : null}</p>
            </div>
          </Link>
        ))}
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="card p-4">
          <h2 className="mb-2 font-semibold">Ventes par jour</h2>
          <ResponsiveContainer width="100%" height={220}>
            <BarChart data={charts.salesByDay.map((d) => ({ ...d, day: formatDate(d.day).split(' ').slice(0, 2).join(' ') }))}>
              <CartesianGrid strokeDasharray="3 3" stroke={themeColor('sand')} />
              <XAxis dataKey="day" tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} tickFormatter={(v: number) => `${Math.round(v / 1000)}k`} />
              <Tooltip formatter={(v: number) => formatFCFA(v)} labelStyle={{ fontSize: 12 }} />
              <Bar dataKey="amount" fill={themeColor('gold')} radius={[6, 6, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
        <div className="card p-4">
          <h2 className="mb-2 font-semibold">Répartition par moyen de paiement</h2>
          <ResponsiveContainer width="100%" height={220}>
            <PieChart>
              <Pie data={charts.byPaymentMethod} dataKey="amount" nameKey="method" innerRadius={50} outerRadius={80} paddingAngle={3}>
                {charts.byPaymentMethod.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
              </Pie>
              <Tooltip formatter={(v: number, name: string) => [formatFCFA(v), PAYMENT_METHOD_LABELS[name as PaymentMethod] ?? name]} />
            </PieChart>
          </ResponsiveContainer>
          <ul className="mt-1 flex flex-wrap gap-3 text-xs">
            {charts.byPaymentMethod.map((m, i) => (
              <li key={m.method} className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full" style={{ background: COLORS[i % COLORS.length] }} />{PAYMENT_METHOD_LABELS[m.method]} ({m.count})</li>
            ))}
          </ul>
        </div>
        <div className="card p-4">
          <h2 className="mb-2 font-semibold">Top 10 produits</h2>
          <ul className="space-y-1.5">
            {charts.topProducts.slice(0, 10).map((p, i) => (
              <li key={p.name} className="flex items-center gap-2 text-sm">
                <span className="w-5 text-xs text-ink-soft">{i + 1}.</span>
                <span className="flex-1 truncate">{p.name}</span>
                <span className="text-xs text-ink-soft">×{p.quantity}</span>
                <span className="font-semibold">{formatFCFA(p.amount)}</span>
              </li>
            ))}
          </ul>
        </div>
        <div className="card p-4">
          <h2 className="mb-2 font-semibold">Ventes par catégorie</h2>
          <ul className="space-y-1.5">
            {charts.salesByCategory.map((c) => (
              <li key={c.name} className="flex items-center gap-2 text-sm">
                <span className="flex-1 truncate">{c.name}</span>
                <span className="font-semibold">{formatFCFA(c.amount)}</span>
              </li>
            ))}
          </ul>
        </div>
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="card p-4">
          <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold">Dernières commandes</h2><Link to="/admin/commandes" className="text-xs text-gold-dark hover:underline">Tout voir</Link></div>
          <ul className="divide-y divide-sand/60">
            {data.latestOrders.map((o) => (
              <li key={o.id}>
                <Link to={`/admin/commandes/${o.id}`} className="flex items-center justify-between gap-3 py-2 text-sm hover:bg-ivory/50">
                  <div><p className="font-mono text-xs font-semibold">{o.number}</p><p className="text-xs text-ink-soft">{o.contactName} · {formatDate(o.createdAt, true)}</p></div>
                  <div className="flex items-center gap-2">
                    <Badge tone={o.paymentStatus === PaymentStatus.PAID ? 'success' : 'warning'}>{PAYMENT_STATUS_LABELS[o.paymentStatus]}</Badge>
                    <span className="font-semibold">{formatFCFA(o.total)}</span>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        </div>
        <div className="card p-4">
          <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold">Derniers messages</h2><Link to="/admin/messagerie" className="text-xs text-gold-dark hover:underline">Tout voir</Link></div>
          {data.latestMessages.length ? (
            <ul className="divide-y divide-sand/60">
              {data.latestMessages.map((m) => (
                <li key={m.id}>
                  <Link to={`/admin/messagerie/${m.id}`} className="flex items-start gap-2 py-2 text-sm hover:bg-ivory/50">
                    {!!m.unread && <BellRing className="mt-0.5 h-4 w-4 shrink-0 text-rose" />}
                    <div className="min-w-0 flex-1">
                      <p className="font-medium">{m.visitorName}</p>
                      <p className="truncate text-xs text-ink-soft">{m.lastMessage ?? '—'}</p>
                    </div>
                    <span className="text-[10px] text-ink-soft">{formatDate(m.lastMessageAt, true)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState icon={<MessageSquare className="h-8 w-8" />} title="Aucun message" text="Les conversations clients apparaîtront ici." />
          )}
        </div>
      </div>
    </div>
  );
}
