import { useEffect, useState } from 'react';
import { Navigate, Outlet, Route, Routes } from 'react-router-dom';
import { AdminLayout } from '@/admin/components/Layout';
import { PageLoader } from '@/admin/components/ui';
import { refreshSession } from '@/admin/lib/api';
import { useAuthStore } from '@/admin/store/auth';
import Audit from '@/admin/pages/Audit';
import ArrivalDetail from '@/admin/pages/ArrivalDetail';
import Arrivals from '@/admin/pages/Arrivals';
import Content from '@/admin/pages/Content';
import CustomerDetail from '@/admin/pages/CustomerDetail';
import Customers from '@/admin/pages/Customers';
import Dashboard from '@/admin/pages/Dashboard';
import Driver from '@/admin/pages/Driver';
import Marketing from '@/admin/pages/Marketing';
import Messaging from '@/admin/pages/Messaging';
import NotFound from '@/admin/pages/NotFound';
import OrderCreate from '@/admin/pages/OrderCreate';
import OrderDetail from '@/admin/pages/OrderDetail';
import Orders from '@/admin/pages/Orders';
import Planning from '@/admin/pages/Planning';
import Preorders from '@/admin/pages/Preorders';
import ProductForm from '@/admin/pages/ProductForm';
import Products from '@/admin/pages/Products';
import Profile from '@/admin/pages/Profile';
import Reports from '@/admin/pages/Reports';
import Settings from '@/admin/pages/Settings';
import Stocks from '@/admin/pages/Stocks';
import Taxonomy from '@/admin/pages/Taxonomy';
import Users from '@/admin/pages/Users';

/** Tente un refresh de session (cookie) si la page est rechargée sans access token. */
function SessionGate() {
  const { hasSession, accessToken } = useAuthStore();
  const [state, setState] = useState<'checking' | 'ok' | 'anonymous'>(accessToken ? 'ok' : 'checking');

  useEffect(() => {
    if (accessToken) return setState('ok');
    if (!hasSession) return setState('anonymous');
    let cancelled = false;
    refreshSession().then((ok) => { if (!cancelled) setState(ok ? 'ok' : 'anonymous'); });
    return () => { cancelled = true; };
  }, [accessToken, hasSession]);

  if (state === 'checking') return <PageLoader />;
  if (state === 'anonymous') return <Navigate to="/compte/connexion" replace />;
  return (
    <AdminLayout>
      <Outlet />
    </AdminLayout>
  );
}

/** Back-office intégré à la boutique : monté sous /admin/*. */
export default function AdminApp() {
  return (
    <div className="admin-scope min-h-screen bg-ivory font-sans text-ink">
      <Routes>
        <Route element={<SessionGate />}>
          <Route index element={<Dashboard />} />
          <Route path="commandes" element={<Orders />} />
          <Route path="commandes/nouvelle" element={<OrderCreate />} />
          <Route path="commandes/:id" element={<OrderDetail />} />
          <Route path="catalogue" element={<Products />} />
          <Route path="catalogue/nouveau" element={<ProductForm />} />
          <Route path="catalogue/:id" element={<ProductForm />} />
          <Route path="taxonomie" element={<Taxonomy />} />
          <Route path="stocks" element={<Stocks />} />
          <Route path="precommandes" element={<Preorders />} />
          <Route path="arrivages" element={<Arrivals />} />
          <Route path="arrivages/:id" element={<ArrivalDetail />} />
          <Route path="livraisons" element={<Planning />} />
          <Route path="livreur" element={<Driver />} />
          <Route path="clients" element={<Customers />} />
          <Route path="clients/:id" element={<CustomerDetail />} />
          <Route path="messagerie" element={<Messaging />} />
          <Route path="messagerie/:id" element={<Messaging />} />
          <Route path="marketing" element={<Marketing />} />
          <Route path="contenus" element={<Content />} />
          <Route path="rapports" element={<Reports />} />
          <Route path="parametres" element={<Settings />} />
          <Route path="utilisateurs" element={<Users />} />
          <Route path="journal" element={<Audit />} />
          <Route path="mon-profil" element={<Profile />} />
          <Route path="*" element={<NotFound />} />
        </Route>
      </Routes>
    </div>
  );
}
