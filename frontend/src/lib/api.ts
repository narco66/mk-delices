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

  constructor(status: number, code: string, message: string, details: Record<string, unknown> = {}, requestId?: string) {
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

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface RequestOptions {
  body?: unknown
  headers?: Record<string, string>
  signal?: AbortSignal
}

const BASE = '/api/v1'
let csrfReady: Promise<void> | null = null

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'))
  return match ? decodeURIComponent(match[1]) : null
}

async function ensureCsrf(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return
  csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'include' }).then(() => undefined)
  await csrfReady
  csrfReady = null
}

export async function request<T>(method: Method, path: string, options: RequestOptions = {}): Promise<T> {
  const isForm = options.body instanceof FormData
  if (method !== 'GET') await ensureCsrf()

  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...options.headers }
  if (!isForm && options.body !== undefined) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf && method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf

  let response: Response
  try {
    response = await fetch(BASE + path, {
      method,
      headers,
      credentials: 'include',
      signal: options.signal,
      body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
    })
  } catch (error) {
    if ((error as Error).name === 'AbortError') throw error
    throw new ApiError(0, 'network_error', 'Connexion impossible. Vérifiez votre réseau puis réessayez.')
  }

  if (response.status === 204) return undefined as T
  const payload = await response.json().catch(() => null)

  if (!response.ok) {
    if (response.status === 419) csrfReady = null
    const err = payload?.error
    throw new ApiError(
      response.status,
      err?.code ?? 'http_error',
      err?.message ?? 'Une erreur est survenue.',
      err?.details ?? {},
      payload?.request_id,
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

/** Clé d'idempotence pour les créations de commande et de session de paiement. */
export function newIdempotencyKey(prefix = 'mkd'): string {
  return `${prefix}-${crypto.randomUUID()}`
}

export function toQuery(params: Record<string, string | number | boolean | null | undefined>): string {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') search.set(k, String(v))
  })
  const s = search.toString()
  return s ? `?${s}` : ''
}
