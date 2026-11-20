import { Component, type ErrorInfo, type ReactNode, useEffect } from 'react'
import { isRouteErrorResponse, useRouteError } from 'react-router'
import { buttonClass } from '@/components/ui/Button'

/**
 * Écran d'erreur commun : explique ce qui se passe et quoi faire, sans jamais afficher de détail technique.
 * L'erreur complète est consignée dans la console du navigateur pour le diagnostic.
 */
function ErrorScreen({ status, onHome = true }: { status?: number; onHome?: boolean }) {
  const offline = typeof navigator !== 'undefined' && navigator.onLine === false
  const notFound = status === 404
  const title = notFound ? 'Page introuvable' : offline ? 'Vous êtes hors ligne' : 'Une erreur est survenue'
  const text = notFound
    ? "Cette page n'existe pas ou a été déplacée."
    : offline
      ? 'Vérifiez votre connexion internet, puis rechargez la page.'
      : 'Rechargez la page. Si le problème persiste, contactez-nous en précisant ce que vous faisiez.'

  return (
    <div role="alert" className="bg-cream-100 grid min-h-dvh place-items-center px-6 text-center">
      <div className="max-w-md">
        <p className="font-display text-caramel-500 text-6xl font-semibold">{notFound ? '404' : 'Oups'}</p>
        <h1 className="mt-4 text-2xl font-semibold">{title}</h1>
        <p className="text-cocoa-500 mt-2">{text}</p>
        <div className="mt-6 flex flex-wrap justify-center gap-3">
          {!notFound && (
            <button type="button" className={buttonClass('primary', 'md')} onClick={() => window.location.reload()}>
              Recharger la page
            </button>
          )}
          {onHome && (
            <a href="/" className={buttonClass(notFound ? 'primary' : 'secondary', 'md')}>
              Retour à l'accueil
            </a>
          )}
        </div>
      </div>
    </div>
  )
}

export function RouteError() {
  const error = useRouteError()
  useEffect(() => {
    if (!isRouteErrorResponse(error) || error.status !== 404) console.error('[MK Délices] Erreur d’affichage', error)
  }, [error])

  return <ErrorScreen status={isRouteErrorResponse(error) ? error.status : undefined} />
}

/**
 * Filet de sécurité au-dessus du routeur : une erreur dans un fournisseur global (session, panier) affiche un
 * écran d'erreur au lieu d'une page blanche.
 */
export class AppErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('[MK Délices] Erreur de l’application', error, info.componentStack)
  }

  render() {
    return this.state.failed ? <ErrorScreen /> : this.props.children
  }
}
