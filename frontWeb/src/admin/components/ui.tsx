import {
  AVAILABILITY_LABELS,
  AvailabilityMode,
  CONVERSATION_STATUS_LABELS,
  ConversationStatus,
  formatFCFA,
  ORDER_STATUS_LABELS,
  OrderStatus,
  PAYMENT_STATUS_LABELS,
  PaymentStatus,
  STEP_LABELS,
  ShipmentStep,
} from '@/shared';
import { AlertCircle, CheckCircle2, ChevronLeft, ChevronRight, Loader2, X } from 'lucide-react';
import { ReactNode, useEffect } from 'react';
import { useUiStore } from '@/admin/store/ui';

export function Spinner({ className = 'h-5 w-5' }: { className?: string }) {
  return <Loader2 className={`animate-spin ${className}`} aria-hidden="true" />;
}

export function PageLoader() {
  return (
    <div className="flex items-center justify-center gap-2 py-16 text-ink-soft" role="status">
      <Spinner /> Chargement…
    </div>
  );
}

export function EmptyState({ icon, title, text, action }: { icon?: ReactNode; title: string; text?: string; action?: ReactNode }) {
  return (
    <div className="card flex flex-col items-center gap-3 px-6 py-12 text-center">
      {icon && <div className="text-gold">{icon}</div>}
      <h2 className="text-xl">{title}</h2>
      {text && <p className="max-w-sm text-sm text-ink-soft">{text}</p>}
      {action}
    </div>
  );
}

export function Alert({ tone = 'info', children }: { tone?: 'info' | 'warning' | 'error' | 'success'; children: ReactNode }) {
  const styles = {
    info: 'bg-sand/60 text-ink',
    warning: 'bg-warning/15 text-[#7a5812]',
    error: 'bg-danger/10 text-danger',
    success: 'bg-success/15 text-[#3f6b4b]',
  }[tone];
  return <div className={`rounded-lg px-4 py-3 text-sm ${styles}`} role={tone === 'error' ? 'alert' : undefined}>{children}</div>;
}

export function Field({ label, error, hint, children }: { label?: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <label className="block">
      {label && <span className="label">{label}</span>}
      {children}
      {hint && !error && <span className="mt-1 block text-xs text-ink-soft">{hint}</span>}
      {error && <span className="field-error" role="alert">{error}</span>}
    </label>
  );
}

export function Modal({ open, onClose, title, children, wide }: { open: boolean; onClose: () => void; title: string; children: ReactNode; wide?: boolean }) {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label={title} onClick={onClose}>
      <div className={`max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 shadow-soft sm:rounded-2xl ${wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'}`} onClick={(e) => e.stopPropagation()}>
        <div className="mb-3 flex items-center justify-between">
          <h2 className="text-lg font-semibold">{title}</h2>
          <button className="btn-ghost !min-h-0 !p-1.5" onClick={onClose} aria-label="Fermer"><X className="h-5 w-5" /></button>
        </div>
        {children}
      </div>
    </div>
  );
}

const TONES: Record<string, string> = {
  gold: 'bg-gold/15 text-gold-dark',
  success: 'bg-success/15 text-[#3f6b4b]',
  warning: 'bg-warning/15 text-[#7a5812]',
  danger: 'bg-danger/10 text-danger',
  muted: 'bg-sand text-ink-soft',
  rose: 'bg-rose/25 text-rose-dark',
};

export function Badge({ tone = 'muted', children }: { tone?: keyof typeof TONES; children: ReactNode }) {
  return <span className={`badge ${TONES[tone]}`}>{children}</span>;
}

const ORDER_TONES: Record<OrderStatus, keyof typeof TONES> = {
  PENDING_PAYMENT: 'warning', CONFIRMED: 'gold', IN_PROGRESS: 'gold', PARTIALLY_DELIVERED: 'gold',
  DELIVERED: 'success', CANCELLED: 'muted', REFUNDED: 'danger',
};
const PAY_TONES: Record<PaymentStatus, keyof typeof TONES> = {
  UNPAID: 'muted', AWAITING_CASH: 'warning', PENDING: 'warning', DEPOSIT_PAID: 'gold',
  PAID: 'success', FAILED: 'danger', PARTIALLY_REFUNDED: 'rose', REFUNDED: 'rose',
};

export function StatusBadge({ value }: { value: OrderStatus | PaymentStatus | AvailabilityMode | ShipmentStep | ConversationStatus | string }) {
  if (value in ORDER_STATUS_LABELS) return <Badge tone={ORDER_TONES[value as OrderStatus]}>{ORDER_STATUS_LABELS[value as OrderStatus]}</Badge>;
  if (value in PAYMENT_STATUS_LABELS) return <Badge tone={PAY_TONES[value as PaymentStatus]}>{PAYMENT_STATUS_LABELS[value as PaymentStatus]}</Badge>;
  if (value in AVAILABILITY_LABELS)
    return <Badge tone={value === AvailabilityMode.IN_STOCK ? 'success' : value === AvailabilityMode.PREORDER ? 'warning' : 'danger'}>{AVAILABILITY_LABELS[value as AvailabilityMode]}</Badge>;
  if (value in STEP_LABELS) return <Badge tone="gold">{STEP_LABELS[value as ShipmentStep]}</Badge>;
  if (value in CONVERSATION_STATUS_LABELS)
    return <Badge tone={value === ConversationStatus.NEW ? 'danger' : value === ConversationStatus.OPEN ? 'gold' : 'muted'}>{CONVERSATION_STATUS_LABELS[value as ConversationStatus]}</Badge>;
  return <Badge>{String(value)}</Badge>;
}

export function Pagination({ page, limit, total, onPage }: { page: number; limit: number; total: number; onPage: (p: number) => void }) {
  const pages = Math.max(1, Math.ceil(total / limit));
  if (pages <= 1) return null;
  return (
    <div className="mt-4 flex items-center justify-between text-sm">
      <span className="text-ink-soft">Page {page} sur {pages} · {total} résultat{total > 1 ? 's' : ''}</span>
      <div className="flex gap-1">
        <button className="btn-ghost !min-h-[32px] !px-2" disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="Page précédente"><ChevronLeft className="h-4 w-4" /></button>
        <button className="btn-ghost !min-h-[32px] !px-2" disabled={page >= pages} onClick={() => onPage(page + 1)} aria-label="Page suivante"><ChevronRight className="h-4 w-4" /></button>
      </div>
    </div>
  );
}

export function DataTable({ head, children }: { head: ReactNode[]; children: ReactNode }) {
  return (
    <div className="card overflow-x-auto">
      <table className="w-full min-w-[640px] border-collapse">
        <thead><tr className="border-b border-sand bg-ivory/60">{head.map((h, i) => <th key={i} className="th">{h}</th>)}</tr></thead>
        <tbody className="divide-y divide-sand/60">{children}</tbody>
      </table>
    </div>
  );
}

export function Money({ value }: { value: number | null | undefined }) {
  return <span className="font-semibold">{formatFCFA(value ?? 0)}</span>;
}

export function Toasts() {
  const { toasts, dismiss } = useUiStore();
  if (!toasts.length) return null;
  return (
    <div className="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-80 flex-col gap-2" role="status" aria-live="polite">
      {toasts.map((t) => (
        <button key={t.id} onClick={() => dismiss(t.id)} className={`pointer-events-auto flex items-center gap-2 rounded-lg px-4 py-3 text-left text-sm text-white shadow-soft ${t.tone === 'error' ? 'bg-danger' : t.tone === 'info' ? 'bg-ink' : 'bg-[#3f6b4b]'}`}>
          {t.tone === 'error' ? <AlertCircle className="h-4 w-4 shrink-0" /> : <CheckCircle2 className="h-4 w-4 shrink-0" />}
          <span>{t.message}</span>
        </button>
      ))}
    </div>
  );
}
