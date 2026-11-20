import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router'
import { toast, Toaster } from 'sonner'
import { AuthProvider } from '@/features/auth/AuthContext'
import { CartProvider } from '@/features/cart/CartContext'
import { ApiError } from '@/lib/api'
import { AppErrorBoundary } from '@/pages/RouteError'
import { router } from '@/routes/router'
import './index.css'

/**
 * Session expirée (audit M10) : sur toute réponse 401 alors qu'un utilisateur était connecté, on l'oublie ;
 * les espaces protégés (RequireAuth) renvoient alors vers /connexion?redirect=… au lieu d'afficher des erreurs.
 */
function onSessionError(error: unknown, queryKey?: readonly unknown[]) {
  if (!(error instanceof ApiError) || error.status !== 401 || queryKey?.[0] === 'me') return
  if (!queryClient.getQueryData(['me'])) return
  queryClient.setQueryData(['me'], null)
  queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== 'me' })
  toast.error('Votre session a expiré : reconnectez-vous pour continuer.')
}

const queryClient: QueryClient = new QueryClient({
  queryCache: new QueryCache({ onError: (error, query) => onSessionError(error, query.queryKey) }),
  mutationCache: new MutationCache({ onError: (error) => onSessionError(error) }),
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      // Pas de nouvelle tentative sur les erreurs métier (4xx).
      retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
    },
  },
})

// Après un déploiement, un onglet resté ouvert peut réclamer un fichier JS qui n'existe plus : on recharge
// la page une fois (au plus une fois par minute, pour ne jamais boucler) au lieu d'afficher un écran cassé.
window.addEventListener('vite:preloadError', (event) => {
  const key = 'mk:reloaded-after-deploy'
  try {
    const last = Number(sessionStorage.getItem(key) ?? 0)
    if (Date.now() - last < 60_000) return
    sessionStorage.setItem(key, String(Date.now()))
  } catch {
    // Stockage indisponible (navigation privée) : on recharge quand même une fois.
  }
  event.preventDefault()
  window.location.reload()
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <CartProvider>
            <RouterProvider router={router} />
            <Toaster
              position="top-center"
              richColors
              closeButton
              toastOptions={{ style: { fontFamily: 'Inter, sans-serif' } }}
            />
          </CartProvider>
        </AuthProvider>
      </QueryClientProvider>
    </AppErrorBoundary>
  </StrictMode>,
)
