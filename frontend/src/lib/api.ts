/**
 * Client HTTP de l'API MK Délices (/api/v1).
 * - Session Sanctum par cookies HttpOnly (aucun jeton d'authentification en localStorage).
 * - Protection CSRF : cookie XSRF-TOKEN renvoyé dans l'en-tête X-XSRF-TOKEN.
 * - Erreurs normalisées { error: { code, message, details }, request_id }.
 */

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details: Record<string, unknown>
  readonly requestId?: string

  constructor(
    status: number,
    code: string,
    message: string,
    details: Record<string, unknown> = {},
    requestId?: string,
  ) {
    super(message)
    this.status = status
    this.code = code
    this.details = details
    this.requestId = requestId
  }

  /** Erreurs de validation par champ (422). */
  get fieldErrors(): Record<string, string[]> {
    return this.code === 'validation_failed' ? (this.details as Record<string, string[]>) : {}
  }
}

/**
 * Message par défaut quand le serveur ne fournit pas le sien (réponse non JSON : nginx en 502/504 pendant une
 * coupure, 413 sur un fichier trop lourd…). Toujours une explication et, si possible, quoi faire.
 */
export function fallbackMessage(status: number): string {
  if (status === 0) return 'Connexion impossible. Vérifiez votre réseau puis réessayez.'
  if (status === 401) return 'Votre session a expiré. Reconnectez-vous pour continuer.'
  if (status === 403) return "Vous n'avez pas l'autorisation d'effectuer cette action."
  if (status === 404) return 'Cet élément est introuvable. Il a peut-être été supprimé.'
  if (status === 413) return 'Le fichier ou les données envoyés sont trop volumineux.'
  if (status === 429) return 'Trop de demandes en peu de temps. Patientez quelques instants puis réessayez.'
  if (status === 502 || status === 503 || status === 504)
    return 'Le service est momentanément indisponible. Réessayez dans quelques instants.'
  if (status >= 500) return 'Une erreur est survenue de notre côté. Réessayez dans quelques instants.'
  return 'La demande a été refusée. Vérifiez les informations saisies.'
}

/** Délai maximal d'une requête (réseau mobile lent) : au-delà, message clair au lieu d'une attente sans fin. */
const TIMEOUT_MS = 30_000
const UPLOAD_TIMEOUT_MS = 120_000

function networkError(error: unknown): ApiError {
  if ((error as Error)?.name === 'TimeoutError') {
    return new ApiError(
      0,
      'timeout',
      'La connexion est trop lente : la demande n’a pas abouti. Réessayez, de préférence avec un meilleur réseau.',
    )
  }
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return new ApiError(0, 'offline', 'Vous êtes hors ligne. Vérifiez votre connexion internet puis réessayez.')
  }
  return new ApiError(0, 'network_error', fallbackMessage(0))
}

/** Signal combinant l'annulation éventuelle de l'appelant et le délai maximal. */
function withTimeout(signal: AbortSignal | undefined, ms: number): AbortSignal {
  const timeout = AbortSignal.timeout(ms)
  return signal && 'any' in AbortSignal ? AbortSignal.any([signal, timeout]) : (signal ?? timeout)
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface RequestOptions {
  body?: unknown
  headers?: Record<string, string>
  signal?: AbortSignal
}

const BASE = '/api/v1'
let csrfReady: Promise<void> | null = null

function readCookie(name: string): string | null {
  const match = document.cookie.match(
    new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'),
  )
  return match ? decodeURIComponent(match[1]) : null
}

/** Obtient un cookie CSRF (une seule requête à la fois). `force` : en redemander un même si un cookie existe (expiré). */
async function ensureCsrf(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) return
  csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'include' }).then(() => undefined)
  await csrfReady
  csrfReady = null
}

export async function request<T>(
  method: Method,
  path: string,
  options: RequestOptions = {},
  retried = false,
): Promise<T> {
  const isForm = options.body instanceof FormData
  if (method !== 'GET') await ensureCsrf()

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...options.headers,
  }
  if (!isForm && options.body !== undefined) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf && method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf

  let response: Response
  try {
    response = await fetch(BASE + path, {
      method,
      headers,
      credentials: 'include',
      signal: withTimeout(options.signal, isForm ? UPLOAD_TIMEOUT_MS : TIMEOUT_MS),
      body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
    })
  } catch (error) {
    // Annulation volontaire (changement de page, saisie suivante) : pas un message pour l'utilisateur.
    if ((error as Error).name === 'AbortError' && options.signal?.aborted) throw error
    throw networkError(error)
  }

  if (response.status === 204) return undefined as T
  const payload = await response.json().catch(() => null)

  if (!response.ok) {
    // Jeton CSRF expiré (419) : on en obtient un neuf et on rejoue la requête une seule fois (audit M10).
    if (response.status === 419 && !retried) {
      await ensureCsrf(true)
      return request<T>(method, path, options, true)
    }
    const err = payload?.error
    throw new ApiError(
      response.status,
      err?.code ?? 'http_error',
      err?.message ?? fallbackMessage(response.status),
      err?.details ?? {},
      payload?.request_id ?? response.headers.get('X-Request-Id') ?? undefined,
    )
  }
  return payload as T
}

export const api = {
  get: <T>(path: string, options?: RequestOptions) => request<T>('GET', path, options),
  post: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('POST', path, { ...options, body }),
  put: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('PUT', path, { ...options, body }),
  patch: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>('PATCH', path, { ...options, body }),
  delete: <T>(path: string, options?: RequestOptions) => request<T>('DELETE', path, options),
}

/**
 * Téléchargement d'un fichier protégé (PDF, CSV) : la requête porte la session et les en-têtes
 * (ex. X-Tracking-Token), ce qu'un simple lien <a href> ne permet pas.
 */
export async function downloadFile(
  path: string,
  filename: string,
  headers: Record<string, string> = {},
): Promise<void> {
  let response: Response
  try {
    response = await fetch(BASE + path, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', ...headers },
      credentials: 'include',
      signal: AbortSignal.timeout(UPLOAD_TIMEOUT_MS),
    })
  } catch (error) {
    throw networkError(error)
  }
  if (!response.ok) {
    const payload = await response.json().catch(() => null)
    throw new ApiError(
      response.status,
      payload?.error?.code ?? 'http_error',
      payload?.error?.message ?? `Téléchargement impossible. ${fallbackMessage(response.status)}`,
      payload?.error?.details ?? {},
      payload?.request_id ?? response.headers.get('X-Request-Id') ?? undefined,
    )
  }
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/** Clé d'idempotence pour les créations de commande et de session de paiement. */
export function newIdempotencyKey(prefix = 'mkd'): string {
  return `${prefix}-${crypto.randomUUID()}`
}

export function toQuery(params: Record<string, string | number | boolean | null | undefined>): string {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    // Laravel (règle `boolean`) n'accepte que 1/0 : « true » en query string serait rejeté (422).
    if (v !== undefined && v !== null && v !== '') search.set(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v))
  })
  const s = search.toString()
  return s ? `?${s}` : ''
}
