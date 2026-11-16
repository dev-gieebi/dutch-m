import { Flower2 } from 'lucide-react';
import { Link } from 'react-router-dom';

export default function NotFound() {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center gap-4 text-center">
      <Flower2 className="h-12 w-12 text-gold" />
      <h1 className="font-serif text-3xl">Page introuvable</h1>
      <p className="text-sm text-ink-soft">Cette page n'existe pas ou vous n'y avez pas accès.</p>
      <Link to="/admin" className="btn-primary">Retour au tableau de bord</Link>
    </div>
  );
}
