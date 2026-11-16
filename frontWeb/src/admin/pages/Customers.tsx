import { formatDate, formatFCFA, formatPhone, Paginated, Permission } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { UserRound, Users } from 'lucide-react';
import { Link, useSearchParams } from 'react-router-dom';
import { Badge, DataTable, EmptyState, PageLoader, Pagination, StatusBadge } from '@/admin/components/ui';
import { api, download, errorMessage } from '@/admin/lib/api';
import { hasPermission, useAuthStore } from '@/admin/store/auth';
import { useUiStore } from '@/admin/store/ui';

interface CustomerRow {
  id: number; fullName: string; phone: string; email: string | null; isGuest: boolean;
  newsletterConsent: boolean; createdAt: string; ordersCount: number; totalSpent: number; lastOrderAt: string | null;
}

export default function Customers() {
  const user = useAuthStore((s) => s.user);
  const notify = useUiStore((s) => s.notify);
  const [params, setParams] = useSearchParams();
  const q = { q: params.get('q') ?? '', newsletter: params.get('newsletter') ?? '', page: Number(params.get('page') ?? 1), limit: 25 };
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v); else next.delete(k);
    next.delete('page');
    setParams(next, { replace: true });
  };
  const { data, isLoading } = useQuery({ queryKey: ['customers', q], queryFn: () => api<Paginated<CustomerRow>>('/admin/customers', { query: q }), placeholderData: (p) => p });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl">Clients</h1>
        {hasPermission(user, Permission.EXPORTS) && <button className="btn-secondary" onClick={() => download('/admin/exports/customers', { format: 'xlsx' }).catch((e) => notify(errorMessage(e), 'error'))}>Exporter (Excel)</button>}
      </div>
      <div className="card flex flex-wrap items-center gap-3 p-4">
        <input className="input max-w-xs" placeholder="Nom, téléphone, e-mail…" value={q.q} onChange={(e) => set('q', e.target.value)} />
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="h-4 w-4 accent-gold" checked={!!q.newsletter} onChange={(e) => set('newsletter', e.target.checked ? 'true' : '')} /> Abonnés newsletter</label>
      </div>
      {isLoading ? <PageLoader /> : !data?.data.length ? (
        <EmptyState icon={<Users className="h-10 w-10" />} title="Aucun client" text="Aucun client ne correspond à ces filtres." />
      ) : (
        <>
          <DataTable head={['Client', 'Contact', 'Compte', 'Commandes', 'Total dépensé', 'Dernière commande', '']}>
            {data.data.map((c) => (
              <tr key={c.id} className="hover:bg-ivory/60">
                <td className="td font-medium">{c.fullName}</td>
                <td className="td"><p className="text-sm">{formatPhone(c.phone)}</p><p className="text-xs text-ink-soft">{c.email ?? '—'}</p></td>
                <td className="td">{c.isGuest ? <Badge>Invité</Badge> : <Badge tone="gold">Compte</Badge>}{c.newsletterConsent && <Badge tone="rose">NL</Badge>}</td>
                <td className="td">{c.ordersCount}</td>
                <td className="td font-semibold">{formatFCFA(c.totalSpent)}</td>
                <td className="td text-xs">{c.lastOrderAt ? formatDate(c.lastOrderAt) : '—'}</td>
                <td className="td"><Link to={`/admin/clients/${c.id}`} className="btn-ghost !min-h-[30px] !px-2 text-xs"><UserRound className="h-4 w-4" /> Fiche</Link></td>
              </tr>
            ))}
          </DataTable>
          <Pagination page={q.page} limit={q.limit} total={data.meta.total} onPage={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); }} />
        </>
      )}
    </div>
  );
}
