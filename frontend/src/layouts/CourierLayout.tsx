import { LogOut } from 'lucide-react'
import { Outlet, useNavigate } from 'react-router'
import { Logo } from '@/components/brand/Brand'
import { useAuth } from '@/features/auth/AuthContext'

/** Espace livreur : interface smartphone épurée. */
export function CourierLayout() {
  const { me, logout } = useAuth()
  const navigate = useNavigate()

  return (
    <div className="min-h-dvh bg-cream-50">
      <a href="#contenu" className="sr-only z-[60] rounded bg-cocoa-700 px-4 py-2 text-white focus:not-sr-only focus:fixed focus:top-2 focus:left-2">
        Aller au contenu
      </a>
      <header className="sticky top-0 z-30 border-b border-cocoa-100/70 bg-cream-100/95 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-xl items-center justify-between px-4">
          <Logo />
          <div className="flex items-center gap-2">
            <span className="hidden text-sm text-cocoa-500 sm:inline">{me?.name}</span>
            <button
              type="button"
              className="grid size-11 place-items-center rounded-full hover:bg-cream-200"
              aria-label="Se déconnecter"
              onClick={async () => {
                await logout()
                navigate('/connexion')
              }}
            >
              <LogOut className="size-5" />
            </button>
          </div>
        </div>
      </header>
      <main id="contenu" className="mx-auto max-w-xl px-4 py-5">
        <Outlet />
      </main>
    </div>
  )
}
