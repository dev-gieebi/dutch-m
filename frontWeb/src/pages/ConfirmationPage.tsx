import {
  buildWhatsAppLink,
  formatDate,
  formatFCFA,
  ItemType,
  OrderTrackingDto,
  PAYMENT_METHOD_LABELS,
  PAYMENT_STATUS_LABELS,
  PaymentMethod,
  STEP_LABELS,
} from '@/shared';
import { CheckCircle2, Copy, MessageCircle } from 'lucide-react';
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Alert, EmptyState, PageSkeleton, Seo } from '@/components/ui';
import { api, assetUrl } from '@/lib/api';
import { useSettings } from '@/lib/hooks';
import { useUiStore } from '@/store/ui';
import { Link } from 'react-router-dom';

export default function ConfirmationPage() {
  const { token = '' } = useParams();
  const { data: settings } = useSettings();
  const notify = useUiStore((s) => s.notify);
  const { data: order, isLoading, isError } = useQuery({
    queryKey: ['order', token],
    queryFn: () => api<OrderTrackingDto>(`/orders/track/${token}`),
    retry: false,
  });
  if (isLoading) return <PageSkeleton />;
  if (isError || !order) {
    return (
      <div className="container-page py-10">
        <EmptyState title="Commande introuvable" text="Ce lien de confirmation est invalide ou a expiré." action={<Link to="/suivi" className="btn-primary">Suivre ma commande</Link>} />
      </div>
    );
  }
  const isCod = order.paymentMethod === PaymentMethod.CASH_ON_DELIVERY;
  const copy = () => {
    void navigator.clipboard.writeText(order.number).then(() => notify('Numéro copié'));
  };
  const wa = settings ? buildWhatsAppLink(settings.whatsappNumber, `Bonjour DutCh’M, je viens de passer la commande ${order.number}.`) : '#';
  return (
    <div className="container-page max-w-3xl py-8">
      <Seo title={`Commande ${order.number}`} noindex />
      <div className="mb-6 text-center">
        <CheckCircle2 className="mx-auto mb-3 h-14 w-14 text-success" />
        <h1 className="section-title !text-3xl">Merci, votre commande est enregistrée !</h1>
        <button onClick={copy} className="mx-auto mt-3 inline-flex items-center gap-2 rounded-xl bg-sand px-4 py-2 font-mono text-sm font-semibold hover:bg-gold/20">
          {order.number} <Copy className="h-4 w-4" aria-label="Copier le numéro" />
        </button>
      </div>
      <Alert tone={isCod ? 'info' : 'warning'}>
        {isCod
          ? `Paiement à la livraison : préparez ${formatFCFA(order.total)} (espèces ou mobile money). ${PAYMENT_STATUS_LABELS[order.paymentStatus]}.`
          : `Paiement ${PAYMENT_METHOD_LABELS[order.paymentMethod]} : ${PAYMENT_STATUS_LABELS[order.paymentStatus]}. Validez la demande reçue sur votre téléphone.`}
      </Alert>
      <div className="card mt-6 space-y-4 p-5">
        <h2 className="font-serif text-xl">Récapitulatif</h2>
        <dl className="grid gap-2 text-sm sm:grid-cols-2">
          <div><dt className="text-ink-soft">Commande passée le</dt><dd className="font-medium">{formatDate(order.createdAt, true)}</dd></div>
          <div><dt className="text-ink-soft">Total</dt><dd className="font-semibold text-gold-dark">{formatFCFA(order.total)}</dd></div>
          {order.depositAmount > 0 && <div><dt className="text-ink-soft">Acompte</dt><dd className="font-medium">{formatFCFA(order.depositAmount)}</dd></div>}
          {order.balanceDue > 0 && <div><dt className="text-ink-soft">Reste à payer</dt><dd className="font-medium">{formatFCFA(order.balanceDue)}</dd></div>}
        </dl>
        {order.shipments.map((s) => (
          <div key={s.id} className="rounded-xl bg-sand/50 p-4">
            <div className="mb-2 flex items-center justify-between text-sm">
              <span className="font-semibold">{s.type === ItemType.LOCAL ? 'Disponible à Libreville' : 'Précommande'}</span>
              <span className="text-ink-soft">{s.currentStep ? STEP_LABELS[s.currentStep] : 'En préparation'}</span>
            </div>
            {s.estimatedDeliveryDate && <p className="text-xs text-ink-soft">Livraison estimée : {formatDate(s.estimatedDeliveryDate)}</p>}
            <ul className="mt-2 space-y-1 text-xs">
              {s.items.map((i, k) => (
                <li key={k} className="flex items-center gap-2">
                  <span className="h-8 w-8 overflow-hidden rounded bg-ivory">{i.imageUrl && <img src={assetUrl(i.imageUrl)} alt="" className="h-full w-full object-cover" />}</span>
                  {i.name} {i.variantName ? `· ${i.variantName}` : ''} × {i.quantity}
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className="mt-6 grid gap-3 sm:grid-cols-2">
        <Link to={`/suivi/${token}`} className="btn-primary">Suivre ma commande</Link>
        <a href={wa} target="_blank" rel="noopener noreferrer" className="btn-secondary"><MessageCircle className="h-4 w-4" /> Nous écrire sur WhatsApp</a>
      </div>
      <p className="mt-4 text-center text-xs text-ink-soft">Conservez votre numéro de commande : il vous sera demandé pour le suivi et à la livraison.</p>
    </div>
  );
}
