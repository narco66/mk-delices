import { Menu, MessageCircle, ShoppingBag, User, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, ScrollRestoration, useLocation } from 'react-router'
import { Logo } from '@/components/brand/Brand'
import { cn } from '@/lib/cn'
import { useAuth } from '@/features/auth/AuthContext'
import { CartDrawer } from '@/features/cart/CartDrawer'
import { useCart } from '@/features/cart/CartContext'
import { usePublicConfig, whatsappLink } from '@/hooks/usePublicData'

const NAV = [
  { to: '/nos-tiramisus', label: 'Nos tiramisus' },
  { to: '/boutique', label: 'Boutique' },
  { to: '/promotions', label: 'Promotions' },
  { to: '/livraison', label: 'Livraison' },
  { to: '/notre-histoire', label: 'Notre histoire' },
  { to: '/contact', label: 'Contact' },
]

export function PublicLayout() {
  // Menu ouvert pour une page donnée : il se referme automatiquement au changement de page.
  const [openPath, setOpenPath] = useState<string | null>(null)
  const [scrolled, setScrolled] = useState(false)
  const { count, setDrawerOpen } = useCart()
  const { me } = useAuth()
  const { data: config } = usePublicConfig()
  const location = useLocation()
  const settings = config?.settings ?? {}
  const wa = whatsappLink(settings['business.whatsapp_number'] as string | null, 'Bonjour MK Délices, je souhaite des informations.')
  const accountHref = me ? (me.is_staff ? '/admin' : me.is_courier ? '/livreur' : '/compte') : '/connexion'

  const menuOpen = openPath === location.pathname
  const setMenuOpen = (value: boolean | ((o: boolean) => boolean)) => setOpenPath((typeof value === 'function' ? value(menuOpen) : value) ? location.pathname : null)
  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  return (
    <div className="flex min-h-dvh flex-col">
      <a href="#contenu" className="sr-only z-[60] rounded bg-cocoa-700 px-4 py-2 text-white focus:not-sr-only focus:fixed focus:top-2 focus:left-2">
        Aller au contenu
      </a>
      <header className={cn('sticky top-0 z-40 transition-all duration-300', scrolled ? 'border-b border-cocoa-100/60 bg-cream-100/90 backdrop-blur-md' : 'bg-cream-100')}>
        <div className="container-page flex h-[72px] items-center justify-between gap-4">
          <Logo />
          <nav aria-label="Navigation principale" className="hidden items-center gap-1 lg:flex">
            {NAV.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                className={({ isActive }) => cn('rounded-full px-3.5 py-2 text-[15px] font-medium transition', isActive ? 'bg-cocoa-700 text-cream-50' : 'text-cocoa-800 hover:bg-cream-200')}
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
          <div className="flex items-center gap-1">
            <Link to={accountHref} className="hidden size-11 place-items-center rounded-full text-cocoa-800 hover:bg-cream-200 sm:grid" aria-label={me ? 'Mon espace' : 'Se connecter'}>
              <User className="size-5" />
            </Link>
            <button type="button" onClick={() => setDrawerOpen(true)} className="relative grid size-11 place-items-center rounded-full text-cocoa-800 hover:bg-cream-200" aria-label={`Ouvrir le panier, ${count} article${count > 1 ? 's' : ''}`}>
              <ShoppingBag className="size-5" />
              {count > 0 && <span className="absolute top-1 right-1 grid min-w-5 place-items-center rounded-full bg-raspberry-500 px-1 text-[11px] font-bold text-white">{count}</span>}
            </button>
            <button type="button" className="grid size-11 place-items-center rounded-full text-cocoa-800 hover:bg-cream-200 lg:hidden" onClick={() => setMenuOpen((o) => !o)} aria-expanded={menuOpen} aria-controls="menu-mobile" aria-label="Menu">
              {menuOpen ? <X className="size-5" /> : <Menu className="size-5" />}
            </button>
          </div>
        </div>
        {menuOpen && (
          <nav id="menu-mobile" aria-label="Navigation mobile" className="border-t border-cocoa-100/60 bg-cream-50 lg:hidden">
            <ul className="container-page grid gap-1 py-3">
              {[...NAV, { to: '/faq', label: 'FAQ' }, { to: accountHref, label: me ? 'Mon espace' : 'Se connecter' }].map((item) => (
                <li key={item.to + item.label}>
                  <NavLink to={item.to} className={({ isActive }) => cn('block rounded-[var(--radius-control)] px-3 py-3 font-medium', isActive ? 'bg-cocoa-700 text-cream-50' : 'text-cocoa-800 hover:bg-cream-200')}>
                    {item.label}
                  </NavLink>
                </li>
              ))}
            </ul>
          </nav>
        )}
      </header>

      <main id="contenu" className="flex-1">
        <Outlet />
      </main>

      {wa && (
        <a href={wa} target="_blank" rel="noopener noreferrer" className="fixed right-4 bottom-4 z-30 grid size-14 place-items-center rounded-full bg-[#1f7a4d] text-white shadow-lift transition hover:scale-105 sm:right-6 sm:bottom-6" aria-label="Nous écrire sur WhatsApp">
          <MessageCircle className="size-6" />
        </a>
      )}

      <Footer settings={settings} />
      <CartDrawer />
      <ScrollRestoration />
    </div>
  )
}

function Footer({ settings }: { settings: Record<string, unknown> }) {
  const phone = settings['business.phone'] as string | null
  const email = settings['business.email'] as string | null
  const instagram = settings['business.instagram_url'] as string | null
  const facebook = settings['business.facebook_url'] as string | null
  const hours = settings['business.hours'] as Record<string, [string, string][]> | undefined
  const days: [string, string][] = [['mon', 'Lun'], ['tue', 'Mar'], ['wed', 'Mer'], ['thu', 'Jeu'], ['fri', 'Ven'], ['sat', 'Sam'], ['sun', 'Dim']]

  return (
    <footer className="mt-20 bg-cocoa-800 text-cream-200">
      <div className="container-page grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-4">
        <div className="space-y-4">
          <Logo tone="light" />
          <p className="max-w-xs text-sm text-cream-300">Tiramisus, gâteaux et douceurs artisanales, préparés à la main et livrés à Dakar.</p>
          <div className="flex gap-2">
            {instagram && (
              <a href={instagram} target="_blank" rel="noopener noreferrer" className="grid size-10 place-items-center rounded-full bg-white/10 hover:bg-white/20" aria-label="Instagram">
                <Instagram className="size-5" />
              </a>
            )}
            {facebook && (
              <a href={facebook} target="_blank" rel="noopener noreferrer" className="grid size-10 place-items-center rounded-full bg-white/10 hover:bg-white/20" aria-label="Facebook">
                <Facebook className="size-5" />
              </a>
            )}
          </div>
        </div>
        <FooterCol title="Boutique" links={[['/nos-tiramisus', 'Nos tiramisus'], ['/boutique', 'Tout le catalogue'], ['/promotions', 'Promotions'], ['/suivi', 'Suivre ma commande']]} />
        <FooterCol title="Informations" links={[['/livraison', 'Livraison'], ['/faq', 'Questions fréquentes'], ['/notre-histoire', 'Notre histoire'], ['/contact', 'Contact'], ['/conditions-generales', 'Conditions générales'], ['/confidentialite', 'Confidentialité'], ['/mentions-legales', 'Mentions légales']]} />
        <div>
          <h2 className="mb-4 font-display text-lg text-cream-50">Horaires de livraison</h2>
          {hours ? (
            <ul className="space-y-1 text-sm">
              {days.map(([key, label]) => (
                <li key={key} className="flex justify-between gap-4">
                  <span className="text-cream-300">{label}</span>
                  <span>{hours[key]?.length ? hours[key].map(([o, c]) => `${o}–${c}`).join(', ') : 'Fermé'}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-cream-300">Horaires communiqués prochainement.</p>
          )}
          <p className="mt-4 text-sm">
            {phone && <span className="block">Tél. {phone}</span>}
            {email && <span className="block">{email}</span>}
            {!phone && !email && <span className="block text-cream-300">Coordonnées en cours de validation.</span>}
          </p>
        </div>
      </div>
      <div className="border-t border-white/10">
        <p className="container-page py-5 text-xs text-cream-300">© {new Date().getFullYear()} MK Délices · Dakar, Sénégal · Prix en francs CFA (XOF)</p>
      </div>
    </footer>
  )
}

function FooterCol({ title, links }: { title: string; links: [string, string][] }) {
  return (
    <div>
      <h2 className="mb-4 font-display text-lg text-cream-50">{title}</h2>
      <ul className="space-y-2 text-sm">
        {links.map(([to, label]) => (
          <li key={to}>
            <Link to={to} className="text-cream-200 hover:text-white hover:underline">
              {label}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}

function Instagram({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className={className} aria-hidden>
      <rect x="3" y="3" width="18" height="18" rx="5" />
      <circle cx="12" cy="12" r="4" />
      <circle cx="17.5" cy="6.5" r="1" fill="currentColor" />
    </svg>
  )
}

function Facebook({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden>
      <path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H8v4h2v6h4v-6h3l1-4h-4V8z" />
    </svg>
  )
}
