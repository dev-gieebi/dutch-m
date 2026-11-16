import { formatFCFA, PaymentRecordStatus, PaymentStatusDto } from '@/shared';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, RefreshCw, Smartphone, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { Alert, EmptyState, PageSkeleton, Seo, Spinner } from '@/components/ui';
import { api, errorMessage } from '@/lib/api';

const POLL_MS = 5000;
const MAX_MS = 2 * 60_000;

export default function PaymentPage() {
  const { paymentId = '' } = useParams();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const qc = useQueryClient();
  const started = useRef(Date.now());
  const [expired, setExpired] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['payment', paymentId],
    queryFn: () => api<PaymentStatusDto>(`/payments/${paymentId}/status`, { query: { token } }),
    refetchInterval: (q) => {
      if (expired) return false;
      const st = q.state.data?.status;
      return st === PaymentRecordStatus.PENDING && Date.now() - started.current < MAX_MS ? POLL_MS : false;
    },
    retry: 1,
  });

  useEffect(() => {
    const t = setInterval(() => {
      if (Date.now() - started.current >= MAX_MS) setExpired(true);
    }, POLL_MS);
    return () => clearInterval(t);
  }, []);

  const refresh = async (check: boolean) => {
    setBusy('check');
    setError(null);
    try {
      if (check) await api(`/payments/${paymentId}/check`, { method: 'POST', query: { token } });
      await qc.invalidateQueries({ queryKey: ['payment', paymentId] });
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  const simulate = async (outcome: 'success' | 'failure') => {
    setBusy(outcome);
    setError(null);
    try {
      const res = await api<PaymentStatusDto>(`/payments/${paymentId}/simulate`, { method: 'POST', body: { outcome, token } });
      qc.setQueryData(['payment', paymentId], res);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  if (isLoading) return <PageSkeleton />;
  if (isError || !data) {
    return (
      <div className="container-page py-10">
        <EmptyState title="Paiement introuvable" text="Ce lien est invalide." action={<Link to="/suivi" className="btn-primary">Suivre ma commande</Link>} />
      </div>
    );
  }

  const pending = data.status === PaymentRecordStatus.PENDING;
  const done = data.status === PaymentRecordStatus.SUCCEEDED;
  const failed = [PaymentRecordStatus.FAILED, PaymentRecordStatus.EXPIRED].includes(data.status);

  return (
    <div className="container-page max-w-xl py-10">
      <Seo title="Paiement en cours" noindex />
      <div className="card p-6 text-center sm:p-8">
        {pending && !expired && (
          <>
            <Smartphone className="mx-auto mb-4 h-14 w-14 text-gold" />
            <h1 className="section-title !text-2xl">Validez sur votre téléphone</h1>
            <p className="mt-3 text-sm text-ink-soft">
              Une demande de paiement de <strong className="text-gold-dark">{formatFCFA(data.amount)}</strong> a été envoyée.
              Composez votre code secret pour confirmer.
            </p>
            {data.instructions && <Alert tone="info">{data.instructions}</Alert>}
            <div className="mt-5 flex items-center justify-center gap-2 text-sm text-ink-soft">
              <Spinner className="h-4 w-4" /> En attente de confirmation…
            </div>
            <button className="btn-primary mt-5 w-full" onClick={() => refresh(true)} disabled={busy === 'check'}>
              {busy === 'check' ? <Spinner /> : <><RefreshCw className="h-4 w-4" /> J'ai validé</>}
            </button>
            {data.isMock && (
              <div className="mt-4 rounded-xl bg-sand p-3 text-xs">
                <p className="mb-2 font-semibold uppercase tracking-wide text-ink-soft">Mode test</p>
                <div className="flex gap-2">
                  <button className="btn-secondary flex-1 !min-h-[40px] text-xs" onClick={() => simulate('success')} disabled={!!busy}>Simuler le succès</button>
                  <button className="btn-secondary flex-1 !min-h-[40px] text-xs" onClick={() => simulate('failure')} disabled={!!busy}>Simuler l'échec</button>
                </div>
              </div>
            )}
          </>
        )}
        {pending && expired && (
          <>
            <XCircle className="mx-auto mb-4 h-14 w-14 text-warning" />
            <h1 className="section-title !text-2xl">Toujours en attente ?</h1>
            <p className="mt-3 text-sm text-ink-soft">Le paiement n'a pas encore été confirmé. Si vous avez validé, cliquez ci-dessous. Sinon, le paiement expirera automatiquement.</p>
            <button className="btn-primary mt-5 w-full" onClick={() => refresh(true)} disabled={busy === 'check'}>
              {busy === 'check' ? <Spinner /> : "Vérifier à nouveau"}
            </button>
          </>
        )}
        {done && (
          <>
            <CheckCircle2 className="mx-auto mb-4 h-14 w-14 text-success" />
            <h1 className="section-title !text-2xl">Paiement confirmé !</h1>
            <p className="mt-3 text-sm text-ink-soft">{formatFCFA(data.amount)} reçus pour la commande {data.orderNumber}. Merci !</p>
            <Link to={`/commande/confirmation/${data.orderTrackingToken}`} className="btn-primary mt-5 w-full">Voir ma commande</Link>
          </>
        )}
        {failed && (
          <>
            <XCircle className="mx-auto mb-4 h-14 w-14 text-danger" />
            <h1 className="section-title !text-2xl">Paiement {data.status === PaymentRecordStatus.EXPIRED ? 'expiré' : 'échoué'}</h1>
            <p className="mt-3 text-sm text-ink-soft">{data.failureReason ?? "Le paiement n'a pas abouti."} Votre réservation de stock a été libérée.</p>
            <div className="mt-5 grid gap-2">
              <Link to={`/suivi/${data.orderTrackingToken}`} className="btn-primary">Suivre ma commande</Link>
              <Link to="/boutique" className="btn-secondary">Recommander</Link>
            </div>
          </>
        )}
        {error && <Alert tone="error">{error}</Alert>}
      </div>
    </div>
  );
}
