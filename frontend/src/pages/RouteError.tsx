import { isRouteErrorResponse, Link, useRouteError } from 'react-router'
import { buttonClass } from '@/components/ui/Button'

export function RouteError() {
  const error = useRouteError()
  const notFound = isRouteErrorResponse(error) && error.status === 404

  return (
    <div className="grid min-h-dvh place-items-center bg-cream-100 px-6 text-center">
      <div className="max-w-md">
        <p className="font-display text-6xl font-semibold text-caramel-500">{notFound ? '404' : 'Oups'}</p>
        <h1 className="mt-4 text-2xl font-semibold">{notFound ? 'Page introuvable' : 'Une erreur est survenue'}</h1>
        <p className="mt-2 text-cocoa-500">{notFound ? "Cette page n'existe pas ou a été déplacée." : 'Rechargez la page ; si le problème persiste, contactez-nous.'}</p>
        <Link to="/" className={buttonClass('primary', 'md', 'mt-6')}>
          Retour à l'accueil
        </Link>
      </div>
    </div>
  )
}
