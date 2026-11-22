import { lazy, Suspense, type ComponentType } from 'react'
import { createBrowserRouter } from 'react-router'
import { Spinner } from '@/components/ui/Feedback'
import { AdminLayout } from '@/layouts/AdminLayout'
import { CourierLayout } from '@/layouts/CourierLayout'
import { PublicLayout } from '@/layouts/PublicLayout'
import { RouteError } from '@/pages/RouteError'
import { RequireAuth } from './RequireAuth'

/** Chargement différé par page (le back-office n'alourdit pas la boutique). */
function page(loader: () => Promise<Record<string, unknown>>, name: string) {
  const Component = lazy(async () => ({ default: (await loader())[name] as ComponentType }))
  return (
    <Suspense fallback={<Spinner />}>
      <Component />
    </Suspense>
  )
}

const pub = () => import('@/pages/public')
const shop = () => import('@/pages/shop')
const checkout = () => import('@/pages/checkout')
const orders = () => import('@/pages/orders')
const account = () => import('@/pages/account')
const adminOrders = () => import('@/pages/admin/orders')
const adminOps = () => import('@/pages/admin/operations')
const adminCatalog = () => import('@/pages/admin/catalog')
const adminSystem = () => import('@/pages/admin/system')
const adminDashboard = () => import('@/pages/admin/dashboard')
const courier = () => import('@/pages/courier')

export const router = createBrowserRouter([
  {
    element: <PublicLayout />,
    errorElement: <RouteError />,
    children: [
      { index: true, element: page(pub, 'HomePage') },
      { path: 'notre-histoire', element: page(pub, 'StoryPage') },
      { path: 'nos-tiramisus', element: page(shop, 'TiramisusPage') },
      { path: 'boutique', element: page(shop, 'ShopPage') },
      { path: 'produits/:slug', element: page(shop, 'ProductPage') },
      { path: 'promotions', element: page(pub, 'PromotionsPage') },
      { path: 'livraison', element: page(pub, 'DeliveryPage') },
      { path: 'contact', element: page(pub, 'ContactPage') },
      { path: 'faq', element: page(pub, 'FaqPage') },
      { path: 'conditions-generales', element: page(pub, 'LegalPage') },
      { path: 'confidentialite', element: page(pub, 'LegalPage') },
      { path: 'mentions-legales', element: page(pub, 'LegalPage') },
      { path: 'panier', element: page(checkout, 'CartPage') },
      { path: 'commander', element: page(checkout, 'CheckoutPage') },
      { path: 'commande/:reference/confirmation', element: page(orders, 'ConfirmationPage') },
      { path: 'paiement/simulateur/:session', element: page(orders, 'SimulatorPage') },
      { path: 'paiement/:reference', element: page(orders, 'PaymentPage') },
      { path: 'paiement/:reference/retour', element: page(orders, 'PaymentReturnPage') },
      { path: 'suivi', element: page(orders, 'TrackingLookupPage') },
      { path: 'suivi/:reference', element: page(orders, 'TrackingPage') },
      { path: 'connexion', element: page(account, 'LoginPage') },
      { path: 'inscription', element: page(account, 'RegisterPage') },
      { path: 'mot-de-passe-oublie', element: page(account, 'ForgotPasswordPage') },
      { path: 'mot-de-passe/nouveau', element: page(account, 'ResetPasswordPage') },
      { path: 'acces-refuse', element: page(account, 'ForbiddenPage') },
      { path: 'compte', element: <RequireAuth role={['client']}>{page(account, 'AccountPage')}</RequireAuth> },
      { path: '*', element: page(pub, 'NotFoundPage') },
    ],
  },
  {
    path: 'admin',
    element: (
      <RequireAuth role={['admin', 'commercial', 'preparateur', 'resp_livraison']}>
        <AdminLayout />
      </RequireAuth>
    ),
    errorElement: <RouteError />,
    children: [
      { index: true, element: page(adminDashboard, 'DashboardPage') },
      { path: 'statistiques', element: <RequireAuth permission={['stats.view']}>{page(adminDashboard, 'StatsPage')}</RequireAuth> },
      { path: 'commandes', element: <RequireAuth permission={['orders.view']}>{page(adminOrders, 'OrdersPage')}</RequireAuth> },
      { path: 'commandes/nouvelle', element: <RequireAuth permission={['orders.manage']}>{page(adminOrders, 'NewOrderPage')}</RequireAuth> },
      { path: 'commandes/:id', element: <RequireAuth permission={['orders.view', 'preparation.manage']}>{page(adminOrders, 'OrderDetailPage')}</RequireAuth> },
      { path: 'preparation', element: <RequireAuth permission={['preparation.view']}>{page(adminOrders, 'PreparationPage')}</RequireAuth> },
      { path: 'paiements', element: <RequireAuth permission={['payments.view']}>{page(adminOrders, 'PaymentsPage')}</RequireAuth> },
      { path: 'remboursements', element: <RequireAuth permission={['payments.view']}>{page(adminOrders, 'RefundsPage')}</RequireAuth> },
      { path: 'factures', element: <RequireAuth permission={['invoices.view']}>{page(adminOrders, 'InvoicesPage')}</RequireAuth> },
      { path: 'livraisons', element: <RequireAuth permission={['deliveries.view']}>{page(adminOps, 'DeliveryBoardPage')}</RequireAuth> },
      { path: 'livraisons/livreurs', element: <RequireAuth permission={['partners.manage']}>{page(adminOps, 'PartnersPage')}</RequireAuth> },
      { path: 'livraisons/zones', element: <RequireAuth permission={['zones.view']}>{page(adminOps, 'ZonesPage')}</RequireAuth> },
      { path: 'livraisons/reglements', element: <RequireAuth permission={['deliveries.settle']}>{page(adminOps, 'PayoutsPage')}</RequireAuth> },
      { path: 'catalogue', element: <RequireAuth permission={['catalog.manage']}>{page(adminCatalog, 'ProductsPage')}</RequireAuth> },
      { path: 'catalogue/nouveau', element: <RequireAuth permission={['catalog.manage']}>{page(adminCatalog, 'ProductEditPage')}</RequireAuth> },
      { path: 'catalogue/categories', element: <RequireAuth permission={['catalog.manage']}>{page(adminCatalog, 'CategoriesPage')}</RequireAuth> },
      { path: 'catalogue/:id', element: <RequireAuth permission={['catalog.manage']}>{page(adminCatalog, 'ProductEditPage')}</RequireAuth> },
      { path: 'promotions', element: <RequireAuth permission={['promotions.manage']}>{page(adminCatalog, 'PromotionsAdminPage')}</RequireAuth> },
      { path: 'avis', element: <RequireAuth permission={['reviews.moderate']}>{page(adminCatalog, 'ReviewsPage')}</RequireAuth> },
      { path: 'contenus', element: <RequireAuth permission={['content.manage']}>{page(adminCatalog, 'ContentAdminPage')}</RequireAuth> },
      { path: 'clients', element: <RequireAuth permission={['customers.view']}>{page(adminSystem, 'CustomersPage')}</RequireAuth> },
      { path: 'clients/:id', element: <RequireAuth permission={['customers.view']}>{page(adminSystem, 'CustomerDetailPage')}</RequireAuth> },
      { path: 'utilisateurs', element: <RequireAuth permission={['users.manage']}>{page(adminSystem, 'UsersPage')}</RequireAuth> },
      { path: 'parametres', element: <RequireAuth permission={['settings.manage']}>{page(adminSystem, 'SettingsPage')}</RequireAuth> },
      { path: 'journal', element: <RequireAuth permission={['audit.view']}>{page(adminSystem, 'AuditPage')}</RequireAuth> },
    ],
  },
  {
    path: 'livreur',
    element: (
      <RequireAuth permission={['courier.missions']}>
        <CourierLayout />
      </RequireAuth>
    ),
    errorElement: <RouteError />,
    children: [
      { index: true, element: page(courier, 'MissionsPage') },
      { path: 'missions/:id', element: page(courier, 'MissionDetailPage') },
    ],
  },
])
