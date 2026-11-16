import { FaqCategoryDto } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, MessageCircleQuestion, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState, PageSkeleton, Seo } from '@/components/ui';
import { api } from '@/lib/api';
import { useUiStore } from '@/store/ui';

export default function FaqPage() {
  const { data, isLoading } = useQuery({ queryKey: ['faq'], queryFn: () => api<FaqCategoryDto[]>('/content/faq'), staleTime: 5 * 60_000 });
  const [q, setQ] = useState('');
  const [open, setOpen] = useState<number | null>(null);
  const openChat = useUiStore((s) => s.openChat);
  const filtered = useMemo(() => {
    if (!data) return [];
    const needle = q.trim().toLowerCase();
    if (!needle) return data;
    return data
      .map((c) => ({ ...c, faqs: c.faqs.filter((f) => f.question.toLowerCase().includes(needle) || f.answer.toLowerCase().includes(needle)) }))
      .filter((c) => c.faqs.length);
  }, [data, q]);
  if (isLoading) return <PageSkeleton />;
  return (
    <div className="container-page max-w-3xl py-8">
      <Seo title="Foire aux questions" description="Réponses aux questions fréquentes : livraison à Libreville, précommandes, paiements mobile money, retours." />
      <h1 className="section-title mb-6 !text-3xl">Foire aux questions</h1>
      <div className="relative mb-6">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-soft" />
        <input className="input !pl-10" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Rechercher une question…" aria-label="Rechercher dans la FAQ" />
      </div>
      {filtered.length ? (
        <div className="space-y-8">
          {filtered.map((cat) => (
            <section key={cat.id}>
              <h2 className="mb-3 font-serif text-xl font-semibold text-gold-dark">{cat.name}</h2>
              <div className="card divide-y divide-sand">
                {cat.faqs.map((f) => (
                  <div key={f.id}>
                    <button
                      className="flex w-full items-center justify-between gap-3 px-4 py-4 text-left text-sm font-medium"
                      onClick={() => setOpen(open === f.id ? null : f.id)}
                      aria-expanded={open === f.id}
                    >
                      {f.question}
                      <ChevronDown className={`h-4 w-4 shrink-0 transition-transform ${open === f.id ? 'rotate-180' : ''}`} />
                    </button>
                    {open === f.id && <div className="prose-amoria px-4 pb-4 text-sm" dangerouslySetInnerHTML={{ __html: f.answer }} />}
                  </div>
                ))}
              </div>
            </section>
          ))}
        </div>
      ) : (
        <EmptyState
          icon={<MessageCircleQuestion className="h-10 w-10" />}
          title="Aucune réponse trouvée"
          text="Notre équipe se fera un plaisir de vous répondre directement."
          action={<button className="btn-primary" onClick={() => openChat()}>Poser ma question</button>}
        />
      )}
    </div>
  );
}
