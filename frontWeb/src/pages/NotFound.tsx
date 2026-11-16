import { Flower2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import { EmptyState, Seo } from '@/components/ui';

export default function NotFound() {
  return (
    <div className="container-page py-16">
      <Seo title="Page introuvable" noindex />
      <EmptyState
        icon={<Flower2 className="h-12 w-12" />}
        title="Cette page s'est envolée"
        text="La page que vous cherchez n'existe pas ou a été déplacée. Laissez-nous vous ramener parmi nos fleurs."
        action={
          <div className="flex flex-wrap justify-center gap-3">
            <Link to="/" className="btn-primary">Retour à l'accueil</Link>
            <Link to="/boutique" className="btn-secondary">Voir la boutique</Link>
          </div>
        }
      />
    </div>
  );
}
