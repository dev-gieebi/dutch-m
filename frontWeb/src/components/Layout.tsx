import { buildWhatsAppLink, CategoryDto, formatPhone } from '@/shared';
import { useQuery } from '@tanstack/react-query';
import { Home, MessageCircle, Search, ShoppingBag, Store, User } from 'lucide-react';
import { ReactNode, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import { api } from '@/lib/api';
import { useSettings } from '@/lib/hooks';
import { useAuthStore } from '@/store/auth';
import { cartCount, useCartStore } from '@/store/cart';
import { useUiStore } from '@/store/ui';
import { ChatWidget } from './ChatWidget';
import { NewsletterForm } from './NewsletterForm';
import { SearchBox } from './SearchBox';
import { Modal, Toast } from './ui';

export function Logo({ className = '' }: { className?: string }) {
  return (
    <Link to="/" className={`flex items-center gap-2.5 leading-none ${className}`} aria-label="DutCh’M – accueil">
      <span className="flex flex-col items-start">
        <img src="/logo.png" alt="DutCh’M" className="h-14 w-auto object-contain sm:h-[72px]" />
        <span className="mt-0.5 text-[9px] uppercase tracking-[0.25em] text-ink-soft">La Fleuriste du Bien-être</span>
      </span>
    </Link>
  );
}

function useCategories() {
  return useQuery({ queryKey: ['categories'], queryFn: () => api<CategoryDto[]>('/catalog/categories'), staleTime: 10 * 60_000 });
}

function Header() {
  const count = useCartStore((s) => cartCount(s.items));
  const customer = useAuthStore((s) => s.customer);
  const { data: categories } = useCategories();
  return (
    <header className="sticky top-0 z-40 border-b border-sand bg-cream/95 backdrop-blur">
      <div className="container-page flex items-center gap-4 py-3">
        <Logo className="shrink-0" />
        <div className="hidden flex-1 md:block">
          <SearchBox />
        </div>
        <nav className="ml-auto flex items-center gap-1" aria-label="Accès rapide">
          <Link to={customer ? '/mon-compte' : '/compte/connexion'} className="btn-ghost hidden !px-3 md:inline-flex" aria-label="Mon compte">
            <User className="h-5 w-5" />
            <span className="hidden lg:inline">{customer ? customer.fullName.split(' ')[0] : 'Connexion'}</span>
          </Link>
          <Link to="/panier" className="btn-ghost relative !px-3" aria-label={`Panier (${count} article${count > 1 ? 's' : ''})`}>
            <ShoppingBag className="h-5 w-5" />
            {count > 0 && (
              <span className="absolute right-1 top-1 flex h-5 min-w-[20px] items-center justify-center rounded-full bg-rose px-1 text-[11px] font-bold text-white">{count}</span>
            )}
          </Link>
        </nav>
      </div>
      <div className="container-page pb-3 md:hidden">
        <SearchBox />
      </div>
      <nav className="hidden border-t border-sand md:block" aria-label="Catégories">
        <ul className="container-page no-scrollbar flex gap-6 overflow-x-auto py-2 text-sm">
          <li><NavLink to="/boutique" className={({ isActive }) => `whitespace-nowrap py-1 ${isActive ? 'text-gold-dark' : 'hover:text-gold-dark'}`}>Toute la boutique</NavLink></li>
          <li><NavLink to="/nouveautes" className="whitespace-nowrap py-1 hover:text-gold-dark">Nouveautés</NavLink></li>
          <li><NavLink to="/promotions" className="whitespace-nowrap py-1 text-rose-dark hover:text-gold-dark">Promotions</NavLink></li>
          {categories?.map((c) => (
            <li key={c.id}>
              <NavLink to={`/categorie/${c.slug}`} className={({ isActive }) => `whitespace-nowrap py-1 ${isActive ? 'text-gold-dark' : 'hover:text-gold-dark'}`}>
                {c.name}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </header>
  );
}

function MobileNav({ onSearch }: { onSearch: () => void }) {
  const count = useCartStore((s) => cartCount(s.items));
  const customer = useAuthStore((s) => s.customer);
  const item = 'flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 text-[11px]';
  const cls = ({ isActive }: { isActive: boolean }) => `${item} ${isActive ? 'text-gold-dark' : 'text-ink-soft'}`;
  return (
    <nav className="pb-safe fixed inset-x-0 bottom-0 z-40 border-t border-sand bg-ivory/95 backdrop-blur md:hidden" aria-label="Navigation principale">
      <div className="flex">
        <NavLink to="/" end className={cls}><Home className="h-5 w-5" />Accueil</NavLink>
        <NavLink to="/boutique" className={cls}><Store className="h-5 w-5" />Boutique</NavLink>
        <button onClick={onSearch} className={`${item} text-ink-soft`}><Search className="h-5 w-5" />Recherche</button>
        <NavLink to="/panier" className={cls}>
          <span className="relative">
            <ShoppingBag className="h-5 w-5" />
            {count > 0 && <span className="absolute -right-2 -top-1 rounded-full bg-rose px-1 text-[10px] font-bold text-white">{count}</span>}
          </span>
          Panier
        </NavLink>
        <NavLink to={customer ? '/mon-compte' : '/compte/connexion'} className={cls}><User className="h-5 w-5" />Compte</NavLink>
      </div>
    </nav>
  );
}

function Footer() {
  const { data: settings } = useSettings();
  const { data: categories } = useCategories();
  const shop = settings?.shop;
  return (
    <footer className="mt-16 bg-ink pb-24 text-ivory/90 md:pb-0">
      <div className="container-page grid gap-8 py-12 md:grid-cols-4">
        <div className="md:col-span-1">
          <p className="font-serif text-2xl tracking-[0.18em] text-gold-light">DutCh’M</p>
          <p className="mt-1 text-xs uppercase tracking-widest text-ivory/60">La Fleuriste du Bien-être</p>
          {shop && (
            <address className="mt-4 space-y-1 text-sm not-italic text-ivory/70">
              <p>{shop.address}</p>
              <p>{formatPhone(shop.phone)}</p>
              <p>{shop.email}</p>
            </address>
          )}
        </div>
        <div>
          <h2 className="mb-3 font-sans text-sm font-semibold uppercase tracking-widest text-gold-light">Boutique</h2>
          <ul className="space-y-2 text-sm">
            {categories?.slice(0, 7).map((c) => (
              <li key={c.id}><Link to={`/categorie/${c.slug}`} className="hover:text-gold-light">{c.name}</Link></li>
            ))}
          </ul>
        </div>
        <div>
          <h2 className="mb-3 font-sans text-sm font-semibold uppercase tracking-widest text-gold-light">Aide</h2>
          <ul className="space-y-2 text-sm">
            <li><Link to="/suivi" className="hover:text-gold-light">Suivi de commande</Link></li>
            <li><Link to="/faq" className="hover:text-gold-light">FAQ</Link></li>
            <li><Link to="/contact" className="hover:text-gold-light">Contact et assistance</Link></li>
            <li><Link to="/pages/livraisons" className="hover:text-gold-light">Livraisons</Link></li>
            <li><Link to="/pages/retours" className="hover:text-gold-light">Annulations et retours</Link></li>
            <li><Link to="/a-propos" className="hover:text-gold-light">À propos</Link></li>
          </ul>
        </div>
        <div>
          <h2 className="mb-3 font-sans text-sm font-semibold uppercase tracking-widest text-gold-light">Newsletter</h2>
          <p className="mb-3 text-sm text-ivory/70">Nouveautés, collections et offres en avant-première.</p>
          <NewsletterForm dark />
        </div>
      </div>
      <div className="border-t border-ivory/10">
        <div className="container-page flex flex-col gap-2 py-4 text-xs text-ivory/60 md:flex-row md:justify-between">
          <p>© {new Date().getFullYear()} DutCh’M – Libreville, Gabon</p>
          <p className="flex flex-wrap gap-4">
            <Link to="/pages/mentions-legales" className="hover:text-gold-light">Mentions légales</Link>
            <Link to="/pages/cgv" className="hover:text-gold-light">CGV</Link>
            <Link to="/pages/confidentialite" className="hover:text-gold-light">Confidentialité</Link>
          </p>
        </div>
      </div>
    </footer>
  );
}

function WhatsAppButton() {
  const { data } = useSettings();
  if (!data?.whatsappNumber) return null;
  return (
    <a
      href={buildWhatsAppLink(data.whatsappNumber, 'Bonjour DutCh’M, j’ai une question.')}
      target="_blank"
      rel="noopener noreferrer"
      className="fixed bottom-20 right-4 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-soft transition hover:-translate-y-0.5 md:bottom-6"
      aria-label="Nous écrire sur WhatsApp"
    >
      <svg viewBox="0 0 24 24" className="h-7 w-7" fill="currentColor" aria-hidden="true">
        <path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.69.25-1.29.17-1.41-.07-.12-.27-.2-.57-.35zM12.04 21.5h-.01a9.45 9.45 0 0 1-4.82-1.32l-.35-.21-3.58.94.96-3.49-.23-.36a9.43 9.43 0 0 1-1.45-5.03c0-5.22 4.25-9.47 9.48-9.47 2.53 0 4.91.99 6.7 2.78a9.4 9.4 0 0 1 2.77 6.7c0 5.22-4.25 9.46-9.47 9.46zm8.06-17.53A11.33 11.33 0 0 0 12.04.63C5.76.63.65 5.74.65 12.02c0 2.01.52 3.97 1.52 5.7L.55 23.63l6.04-1.58a11.37 11.37 0 0 0 5.45 1.39h.01c6.28 0 11.39-5.11 11.39-11.39 0-3.04-1.18-5.9-3.34-8.06z" />
      </svg>
    </a>
  );
}

function CookieBanner() {
  const consent = useUiStore((s) => s.cookieConsent);
  const setConsent = useUiStore((s) => s.setCookieConsent);
  if (consent) return null;
  return (
    <div className="fixed inset-x-3 bottom-20 z-50 mx-auto max-w-xl rounded-xl bg-ink p-4 text-sm text-ivory shadow-soft md:bottom-6" role="region" aria-label="Cookies">
      <p>Nous utilisons uniquement les cookies strictement nécessaires au fonctionnement de la boutique (panier, connexion). <Link to="/pages/confidentialite" className="underline">En savoir plus</Link></p>
      <div className="mt-3 flex justify-end gap-2">
        <button className="btn-primary !min-h-[40px] !py-1.5" onClick={() => setConsent('necessary')}>J’ai compris</button>
      </div>
    </div>
  );
}

export function Layout({ children }: { children: ReactNode }) {
  const [searchOpen, setSearchOpen] = useState(false);
  const { pathname } = useLocation();
  return (
    <div className="flex min-h-screen flex-col">
      <a href="#contenu" className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-ivory focus:p-2">Aller au contenu</a>
      <Header />
      <main id="contenu" className="flex-1 pb-24 md:pb-0">
        {children}
      </main>
      <Footer />
      <MobileNav onSearch={() => setSearchOpen(true)} />
      {!pathname.startsWith('/commande') && <WhatsAppButton />}
      <ChatLauncher />
      <ChatWidget />
      <CookieBanner />
      <Toast />
      <Modal open={searchOpen} onClose={() => setSearchOpen(false)} title="Rechercher">
        <SearchBox autoFocus />
      </Modal>
    </div>
  );
}

function ChatLauncher() {
  const open = useUiStore((s) => s.chatOpen);
  const openChat = useUiStore((s) => s.openChat);
  if (open) return null;
  return (
    <button
      onClick={() => openChat()}
      className="fixed bottom-36 right-4 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-gold-gradient text-white shadow-soft transition hover:-translate-y-0.5 md:bottom-24"
      aria-label="Discuter avec DutCh’M"
    >
      <MessageCircle className="h-6 w-6" />
    </button>
  );
}
