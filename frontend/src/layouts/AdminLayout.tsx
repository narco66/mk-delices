import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  BarChart3,
  Bell,
  Bike,
  ChefHat,
  ClipboardList,
  CreditCard,
  FileText,
  LayoutDashboard,
  LogOut,
  MapPin,
  Menu,
  Package,
  ReceiptText,
  ScrollText,
  Settings,
  Star,
  Tag,
  Undo2,
  Users,
  UserCog,
  Wallet,
  X,
} from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { Logo } from '@/components/brand/Brand'
import { useAuth } from '@/features/auth/AuthContext'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatDateTime } from '@/lib/format'

interface NavItem {
  to: string
  label: string
  icon: ReactNode
  permission?: string
  end?: boolean
}

/** Menus filtrés selon les permissions (confort UI) ; les restrictions réelles sont appliquées par l'API. */
const SECTIONS: { title: string; items: NavItem[] }[] = [
  {
    title: 'Pilotage',
    items: [
      { to: '/admin', label: 'Vue générale', icon: <LayoutDashboard />, end: true },
      { to: '/admin/statistiques', label: 'Statistiques', icon: <BarChart3 />, permission: 'stats.view' },
    ],
  },
  {
    title: 'Opérations',
    items: [
      { to: '/admin/commandes', label: 'Commandes', icon: <ClipboardList />, permission: 'orders.view' },
      { to: '/admin/preparation', label: 'Préparation', icon: <ChefHat />, permission: 'preparation.view' },
      { to: '/admin/livraisons', label: 'Livraisons', icon: <Bike />, permission: 'deliveries.view' },
      { to: '/admin/livraisons/livreurs', label: 'Livreurs', icon: <Users />, permission: 'partners.manage' },
      { to: '/admin/livraisons/zones', label: 'Zones & tarifs', icon: <MapPin />, permission: 'zones.view' },
      { to: '/admin/livraisons/reglements', label: 'Règlements livreurs', icon: <Wallet />, permission: 'deliveries.settle' },
    ],
  },
  {
    title: 'Finances',
    items: [
      { to: '/admin/paiements', label: 'Paiements', icon: <CreditCard />, permission: 'payments.view' },
      { to: '/admin/remboursements', label: 'Remboursements', icon: <Undo2 />, permission: 'payments.view' },
      { to: '/admin/factures', label: 'Factures', icon: <ReceiptText />, permission: 'invoices.view' },
    ],
  },
  {
    title: 'Commerce',
    items: [
      { to: '/admin/catalogue', label: 'Catalogue', icon: <Package />, permission: 'catalog.manage' },
      { to: '/admin/promotions', label: 'Promotions', icon: <Tag />, permission: 'promotions.manage' },
      { to: '/admin/clients', label: 'Clients', icon: <Users />, permission: 'customers.view' },
      { to: '/admin/avis', label: 'Avis', icon: <Star />, permission: 'reviews.moderate' },
      { to: '/admin/contenus', label: 'Contenus du site', icon: <FileText />, permission: 'content.manage' },
    ],
  },
  {
    title: 'Administration',
    items: [
      { to: '/admin/utilisateurs', label: 'Utilisateurs', icon: <UserCog />, permission: 'users.manage' },
      { to: '/admin/parametres', label: 'Paramètres', icon: <Settings />, permission: 'settings.manage' },
      { to: '/admin/journal', label: "Journal d'audit", icon: <ScrollText />, permission: 'audit.view' },
    ],
  },
]

const ROLE_LABELS: Record<string, string> = {
  admin: 'Administrateur',
  commercial: 'Gestionnaire commercial',
  preparateur: 'Préparateur',
  resp_livraison: 'Responsable livraisons',
}

export function AdminLayout() {
  const { me, can, logout } = useAuth()
  // Menu ouvert pour une page donnée : il se referme automatiquement au changement de page.
  const [openPath, setOpenPath] = useState<string | null>(null)
  const location = useLocation()
  const navigate = useNavigate()
  const open = openPath === location.pathname
  const setOpen = (value: boolean | ((o: boolean) => boolean)) => setOpenPath((typeof value === 'function' ? value(open) : value) ? location.pathname : null)

  const sections = SECTIONS.map((s) => ({ ...s, items: s.items.filter((i) => !i.permission || can(i.permission)) })).filter((s) => s.items.length)

  const sidebar = (
    <nav aria-label="Back-office" className="flex h-full flex-col">
      <div className="px-5 py-5">
        <Logo />
      </div>
      <div className="flex-1 space-y-6 overflow-y-auto px-3 pb-6">
        {sections.map((section) => (
          <div key={section.title}>
            <p className="mb-1.5 px-3 text-[11px] font-semibold tracking-[0.16em] text-cocoa-400 uppercase">{section.title}</p>
            <ul className="space-y-0.5">
              {section.items.map((item) => (
                <li key={item.to}>
                  <NavLink
                    to={item.to}
                    end={item.end ?? item.to === '/admin/livraisons'}
                    className={({ isActive }) =>
                      cn('flex items-center gap-3 rounded-[var(--radius-control)] px-3 py-2.5 text-sm font-medium transition [&_svg]:size-[18px]', isActive ? 'bg-cocoa-700 text-cream-50 shadow-soft' : 'text-cocoa-800 hover:bg-cream-200')
                    }
                  >
                    {item.icon}
                    {item.label}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className="border-t border-cocoa-100/70 p-4">
        <p className="truncate text-sm font-semibold">{me?.name}</p>
        <p className="text-xs text-cocoa-500">{me?.roles.map((r) => ROLE_LABELS[r] ?? r).join(', ')}</p>
        <div className="mt-3 flex gap-2">
          <Link to="/" className="flex-1 rounded-[var(--radius-control)] border border-cocoa-100 px-3 py-2 text-center text-xs font-medium hover:bg-cream-200">
            Voir la boutique
          </Link>
          <button
            type="button"
            onClick={async () => {
              await logout()
              navigate('/connexion')
            }}
            className="grid size-9 place-items-center rounded-[var(--radius-control)] border border-cocoa-100 hover:bg-cream-200"
            aria-label="Se déconnecter"
          >
            <LogOut className="size-4" />
          </button>
        </div>
      </div>
    </nav>
  )

  return (
    <div className="min-h-dvh bg-cream-50 lg:grid lg:grid-cols-[260px_1fr]">
      <aside className="sticky top-0 hidden h-dvh border-r border-cocoa-100/70 bg-cream-100 lg:block">{sidebar}</aside>
      {open && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button type="button" className="absolute inset-0 bg-cocoa-900/40" aria-label="Fermer le menu" onClick={() => setOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-[280px] bg-cream-100 shadow-lift">{sidebar}</aside>
        </div>
      )}
      <div className="min-w-0">
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b border-cocoa-100/70 bg-cream-50/90 px-4 backdrop-blur sm:px-6">
          <button type="button" className="grid size-10 place-items-center rounded-full hover:bg-cream-200 lg:hidden" onClick={() => setOpen(true)} aria-label="Ouvrir le menu">
            {open ? <X className="size-5" /> : <Menu className="size-5" />}
          </button>
          <p className="hidden text-sm text-cocoa-500 sm:block">Back-office MK Délices</p>
          <Notifications />
        </header>
        <main id="contenu" className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

interface NotificationItem {
  id: string
  data: { message: string; reference: string; order_id: string }
  read_at: string | null
  created_at: string
}

function Notifications() {
  const [open, setOpen] = useState(false)
  const queryClient = useQueryClient()
  const { data } = useQuery({
    queryKey: ['admin', 'notifications'],
    queryFn: () => api.get<{ data: NotificationItem[]; meta: { unread: number } }>('/admin/notifications'),
    refetchInterval: 60_000,
  })
  const markRead = useMutation({
    mutationFn: () => api.post('/admin/notifications/read'),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'notifications'] }),
  })
  const unread = data?.meta.unread ?? 0

  return (
    <div className="relative">
      <button type="button" onClick={() => setOpen((o) => !o)} className="relative grid size-10 place-items-center rounded-full hover:bg-cream-200" aria-expanded={open} aria-label={`Notifications, ${unread} non lue${unread > 1 ? 's' : ''}`}>
        <Bell className="size-5" />
        {unread > 0 && <span className="absolute top-1 right-1 grid min-w-4 place-items-center rounded-full bg-raspberry-500 px-1 text-[10px] font-bold text-white">{unread}</span>}
      </button>
      {open && (
        <div className="absolute right-0 mt-2 w-[min(92vw,360px)] overflow-hidden rounded-[var(--radius-card)] border border-cocoa-100 bg-white shadow-lift">
          <div className="flex items-center justify-between border-b border-cocoa-100/70 px-4 py-3">
            <p className="font-semibold">Notifications</p>
            {unread > 0 && (
              <button type="button" className="text-xs font-medium text-raspberry-600 hover:underline" onClick={() => markRead.mutate()}>
                Tout marquer comme lu
              </button>
            )}
          </div>
          <ul className="max-h-96 overflow-y-auto">
            {(data?.data ?? []).length === 0 && <li className="px-4 py-6 text-center text-sm text-cocoa-500">Aucune notification.</li>}
            {(data?.data ?? []).map((n) => (
              <li key={n.id} className={cn('border-b border-cocoa-100/50 last:border-0', !n.read_at && 'bg-raspberry-50/50')}>
                <Link to={`/admin/commandes/${n.data.order_id}`} onClick={() => setOpen(false)} className="block px-4 py-3 hover:bg-cream-100">
                  <p className="text-sm font-medium">{n.data.message}</p>
                  <p className="text-xs text-cocoa-500">
                    {n.data.reference} · {formatDateTime(n.created_at)}
                  </p>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
