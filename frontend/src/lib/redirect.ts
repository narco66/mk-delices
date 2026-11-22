/**
 * Redirection après connexion limitée au site lui-même (audit W-07 du 22/11/2026) : l'adresse est résolue
 * par le navigateur puis son origine comparée à celle du site. Sont écartés « //domaine », « /\domaine »,
 * les schémas (« javascript: ») et toute adresse absolue vers un autre domaine.
 */
export function safeRedirect(target: string | null, origin: string = window.location.origin): string | null {
  if (!target || !target.startsWith('/')) return null
  try {
    const url = new URL(target, origin)
    return url.origin === origin ? `${url.pathname}${url.search}${url.hash}` : null
  } catch {
    return null
  }
}
