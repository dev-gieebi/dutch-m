import { AvailabilityMode, formatFCFA } from '@/shared';
import { AlertCircle, CheckCircle2, Clock, Loader2, X } from 'lucide-react';
import { ReactNode, useEffect } from 'react';
import { Helmet } from 'react-helmet-async';
import { Link } from 'react-router-dom';
import { SITE_URL } from '@/lib/api';
import { useUiStore } from '@/store/ui';

export function Skeleton({ className = '' }: { className?: string }) {
  return <div className={`animate-pulse rounded-xl bg-sand/80 ${className}`} aria-hidden="true" />;
}

export function PageSkeleton() {
  return (
    <div className="container-page space-y-4 py-8" aria-busy="true" aria-label="Chargement">
      <Skeleton className="h-8 w-1/3" />
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        {Array.from({ length: 8 }).map((_, i) => (
          <Skeleton key={i} className="aspect-[3/4]" />
        ))}
      </div>
    </div>
  );
}

export function Spinner({ className = 'h-5 w-5' }: { className?: string }) {
  return <Loader2 className={`animate-spin ${className}`} aria-hidden="true" />;
}

export function EmptyState({ icon, title, text, action }: { icon?: ReactNode; title: string; text?: string; action?: ReactNode }) {
  return (
    <div className="card mx-auto flex max-w-md flex-col items-center gap-3 px-6 py-12 text-center">
      {icon && <div className="text-gold">{icon}</div>}
      <h2 className="text-2xl">{title}</h2>
      {text && <p className="text-sm text-ink-soft">{text}</p>}
      {action}
    </div>
  );
}

export function Price({ price, compareAt, className = '' }: { price: number; compareAt?: number | null; className?: string }) {
  return (
    <span className={`inline-flex flex-wrap items-baseline gap-2 ${className}`}>
      <span className="font-semibold text-gold-dark">{formatFCFA(price)}</span>
      {compareAt ? <s className="text-sm text-ink-soft">{formatFCFA(compareAt)}</s> : null}
    </span>
  );
}

export function AvailabilityBadge({ availability, leadTimeDays }: { availability: AvailabilityMode; leadTimeDays?: number }) {
  if (availability === AvailabilityMode.IN_STOCK)
    return (
      <span className="badge bg-success/15 text-success">
        <CheckCircle2 className="h-3.5 w-3.5" /> En stock
      </span>
    );
  if (availability === AvailabilityMode.PREORDER)
    return (
      <span className="badge bg-warning/15 text-[#8a6416]">
        <Clock className="h-3.5 w-3.5" /> Sur commande · {leadTimeDays ?? 14} j
      </span>
    );
  return (
    <span className="badge bg-danger/15 text-danger">
      <AlertCircle className="h-3.5 w-3.5" /> Indisponible
    </span>
  );
}

export function Field({ label, error, children, hint }: { label: string; error?: string; children: ReactNode; hint?: string }) {
  return (
    <label className="block">
      <span className="label">{label}</span>
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-ink-soft">{hint}</span>}
      {error && (
        <span className="field-error" role="alert">
          {error}
        </span>
      )}
    </label>
  );
}

export function Seo({ title, description, image, type = 'website', jsonLd, noindex }: {
  title: string; description?: string | null; image?: string | null; type?: string; jsonLd?: object; noindex?: boolean;
}) {
  const fullTitle = title.includes('DutCh’M') ? title : `${title} | DutCh’M`;
  const url = `${SITE_URL}${window.location.pathname}`;
  // Les réseaux sociaux exigent des URL d'image absolues.
  const imageUrl = image ? (/^https?:\/\//.test(image) ? image : `${SITE_URL}${image.startsWith('/') ? '' : '/'}${image}`) : null;
  return (
    <Helmet>
      <title>{fullTitle}</title>
      {description && <meta name="description" content={description} />}
      <link rel="canonical" href={url} />
      <meta name="robots" content={noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large'} />
      <meta property="og:site_name" content="DutCh’M" />
      <meta property="og:title" content={fullTitle} />
      {description && <meta property="og:description" content={description} />}
      <meta property="og:type" content={type} />
      <meta property="og:url" content={url} />
      <meta property="og:locale" content="fr_FR" />
      {imageUrl && <meta property="og:image" content={imageUrl} />}
      <meta name="twitter:card" content={imageUrl ? 'summary_large_image' : 'summary'} />
      <meta name="twitter:title" content={fullTitle} />
      {description && <meta name="twitter:description" content={description} />}
      {imageUrl && <meta name="twitter:image" content={imageUrl} />}
      {jsonLd && <script type="application/ld+json">{JSON.stringify(jsonLd)}</script>}
    </Helmet>
  );
}

export function Breadcrumbs({ items }: { items: { label: string; to?: string }[] }) {
  return (
    <nav aria-label="Fil d'Ariane" className="mb-4 text-xs text-ink-soft">
      <ol className="flex flex-wrap items-center gap-1">
        <li>
          <Link to="/" className="hover:text-gold-dark">
            Accueil
          </Link>
        </li>
        {items.map((i, idx) => (
          <li key={idx} className="flex items-center gap-1">
            <span aria-hidden="true">›</span>
            {i.to ? (
              <Link to={i.to} className="hover:text-gold-dark">
                {i.label}
              </Link>
            ) : (
              <span aria-current="page">{i.label}</span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}

export function Modal({ open, onClose, title, children }: { open: boolean; onClose: () => void; title: string; children: ReactNode }) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-0 animate-fade-in sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label={title} onClick={onClose}>
      <div className="max-h-[90vh] w-full overflow-y-auto rounded-t-2xl bg-ivory p-5 shadow-soft sm:max-w-lg sm:rounded-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-xl">{title}</h2>
          <button className="btn-ghost !min-h-0 !p-2" onClick={onClose} aria-label="Fermer">
            <X className="h-5 w-5" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}

export function Toast() {
  const toast = useUiStore((s) => s.toast);
  const dismiss = useUiStore((s) => s.dismissToast);
  useEffect(() => {
    if (!toast) return;
    const t = setTimeout(dismiss, 3500);
    return () => clearTimeout(t);
  }, [toast, dismiss]);
  if (!toast) return null;
  return (
    <div role="status" className="fixed inset-x-4 bottom-24 z-[60] mx-auto max-w-sm animate-rise md:bottom-8">
      <div className={`flex items-center gap-2 rounded-xl px-4 py-3 text-sm text-white shadow-soft ${toast.tone === 'error' ? 'bg-danger' : 'bg-ink'}`}>
        {toast.tone === 'error' ? <AlertCircle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4 text-gold-light" />}
        <span className="flex-1">{toast.message}</span>
      </div>
    </div>
  );
}

export function Alert({ tone = 'info', children }: { tone?: 'info' | 'warning' | 'error' | 'success'; children: ReactNode }) {
  const styles = {
    info: 'bg-sand text-ink',
    warning: 'bg-warning/15 text-[#7a5812]',
    error: 'bg-danger/10 text-danger',
    success: 'bg-success/15 text-[#3f6b4b]',
  }[tone];
  return <div className={`rounded-xl px-4 py-3 text-sm ${styles}`} role={tone === 'error' ? 'alert' : undefined}>{children}</div>;
}
