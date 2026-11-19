import { lazy, Suspense, useEffect, useRef } from 'react';
import { Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { Layout } from './components/Layout';
import { PageSkeleton } from './components/ui';
import { refreshSession } from './lib/api';
import { useCartSync, useSettings } from './lib/hooks';
import { applyTheme } from './lib/theme';
import { useAuthStore } from './store/auth';

const Home = lazy(() => import('./pages/Home'));
const Catalog = lazy(() => import('./pages/Catalog'));
const ProductPage = lazy(() => import('./pages/ProductPage'));
const CartPage = lazy(() => import('./pages/CartPage'));
const CheckoutPage = lazy(() => import('./pages/CheckoutPage'));
const ConfirmationPage = lazy(() => import('./pages/ConfirmationPage'));
const PaymentPage = lazy(() => import('./pages/PaymentPage'));
const TrackingPage = lazy(() => import('./pages/TrackingPage'));
const SearchPage = lazy(() => import('./pages/SearchPage'));
const ContentPage = lazy(() => import('./pages/ContentPage'));
const FaqPage = lazy(() => import('./pages/FaqPage'));
const ContactPage = lazy(() => import('./pages/ContactPage'));
const NotFound = lazy(() => import('./pages/NotFound'));
const AuthPages = lazy(() => import('./pages/account/AuthPages'));
const AccountPages = lazy(() => import('./pages/account/AccountPages'));
const AdminApp = lazy(() => import('./admin/AdminApp'));

function ShopShell() {
  return (
    <Layout>
      <Outlet />
    </Layout>
  );
}

export default function App() {
  const { pathname } = useLocation();
  const hasSession = useAuthStore((s) => s.hasSession);
  useCartSync();
  const theme = useSettings().data?.theme;
  useEffect(() => { if (theme) applyTheme(theme); }, [theme]);

  useEffect(() => { window.scrollTo(0, 0); }, [pathname]);
  // Les balises SEO injectées par le serveur (data-ssr) ne valent que pour la première page affichée.
  const firstPath = useRef(pathname);
  useEffect(() => {
    if (pathname !== firstPath.current) document.querySelectorAll('[data-ssr]').forEach((el) => el.remove());
  }, [pathname]);
  useEffect(() => {
    if (hasSession) void refreshSession();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <Suspense fallback={<PageSkeleton />}>
      <Routes>
        <Route path="/admin/*" element={<AdminApp />} />
        <Route element={<ShopShell />}>
          <Route path="/" element={<Home />} />
          <Route path="/boutique" element={<Catalog mode="all" />} />
          <Route path="/categorie/:slug" element={<Catalog mode="category" />} />
          <Route path="/collection/:slug" element={<Catalog mode="collection" />} />
          <Route path="/nouveautes" element={<Catalog mode="new" />} />
          <Route path="/promotions" element={<Catalog mode="sale" />} />
          <Route path="/recherche" element={<SearchPage />} />
          <Route path="/produit/:slug" element={<ProductPage />} />
          <Route path="/panier" element={<CartPage />} />
          <Route path="/commande" element={<CheckoutPage />} />
          <Route path="/commande/confirmation/:token" element={<ConfirmationPage />} />
          <Route path="/paiement/:paymentId" element={<PaymentPage />} />
          <Route path="/suivi" element={<TrackingPage />} />
          <Route path="/suivi/:token" element={<TrackingPage />} />
          <Route path="/a-propos" element={<ContentPage slug="a-propos" />} />
          <Route path="/pages/:slug" element={<ContentPage />} />
          <Route path="/faq" element={<FaqPage />} />
          <Route path="/contact" element={<ContactPage />} />
          <Route path="/compte/*" element={<AuthPages />} />
          <Route path="/mon-compte/*" element={<AccountPages />} />
          <Route path="*" element={<NotFound />} />
        </Route>
      </Routes>
    </Suspense>
  );
}
