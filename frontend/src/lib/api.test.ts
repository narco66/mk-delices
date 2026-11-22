import { afterEach, describe, expect, it, vi } from 'vitest'
import { errorMessage } from '@/components/ui/Feedback'
import { api, ApiError, toQuery } from './api'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('client API', () => {
  it("normalise les erreurs de l'API et expose les erreurs de champ", async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          error: { code: 'validation_failed', message: 'Invalide', details: { 'customer.phone': ['Requis'] } },
          request_id: 'req-1',
        }),
        { status: 422 },
      ),
    )
    const error = (await api.get('/x').catch((e: unknown) => e)) as ApiError
    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(422)
    expect(error.fieldErrors['customer.phone']).toEqual(['Requis'])
    expect(error.requestId).toBe('req-1')
  })

  it("envoie le jeton CSRF sur les écritures et jamais d'en-tête d'authentification", async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D'
    const spy = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValue(new Response(JSON.stringify({ data: 1 }), { status: 200 }))
    await api.post('/cart', { a: 1 })
    const [, init] = spy.mock.calls[0]
    const headers = init!.headers as Record<string, string>
    expect(headers['X-XSRF-TOKEN']).toBe('abc=')
    expect(headers.Authorization).toBeUndefined()
    expect(init!.credentials).toBe('include')
  })

  it('renouvelle le jeton CSRF expiré (419) et rejoue la requête une seule fois', async () => {
    document.cookie = 'XSRF-TOKEN=ancien'
    const spy = vi
      .spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ error: { code: 'csrf', message: 'Expiré' } }), { status: 419 }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 })) // /sanctum/csrf-cookie
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: 'ok' }), { status: 200 }))
    await expect(api.post('/cart', {})).resolves.toEqual({ data: 'ok' })
    expect(spy).toHaveBeenCalledTimes(3)
    expect(String(spy.mock.calls[1][0])).toBe('/sanctum/csrf-cookie')

    // Un second 419 consécutif n'entraîne pas de boucle : l'erreur est remontée.
    spy.mockReset()
    spy
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ error: { code: 'csrf', message: 'Expiré' } }), { status: 419 }),
      )
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ error: { code: 'csrf', message: 'Expiré' } }), { status: 419 }),
      )
    await expect(api.post('/cart', {})).rejects.toMatchObject({ status: 419 })
    expect(spy).toHaveBeenCalledTimes(3)
  })

  it('une coupure pendant la demande du jeton CSRF ne bloque pas les essais suivants', async () => {
    // Audit du 22/11/2026 (W-03) : l'échec n'est plus gardé en mémoire ; message réseau au lieu d'une erreur technique.
    const fetchMock = vi
      .spyOn(globalThis, 'fetch')
      .mockRejectedValueOnce(new TypeError('Failed to fetch'))
      .mockImplementationOnce(async () => {
        document.cookie = 'XSRF-TOKEN=neuf'
        return new Response(null, { status: 204 })
      })
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: { ok: true } }), { status: 200 }))

    const first = (await api.post('/x', {}).catch((e: unknown) => e)) as ApiError
    expect(first).toBeInstanceOf(ApiError)
    expect(first.status).toBe(0)

    await expect(api.post('/x', {})).resolves.toEqual({ data: { ok: true } })
    expect(fetchMock).toHaveBeenCalledTimes(3)
    expect(fetchMock.mock.calls[1][0]).toBe('/sanctum/csrf-cookie')
  })

  it('signale une erreur réseau lisible', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('Failed to fetch'))
    await expect(api.get('/x')).rejects.toMatchObject({ code: 'network_error', status: 0 })
  })

  it('construit des paramètres de requête sans valeurs vides', () => {
    expect(toQuery({ q: 'tiramisu', page: 2, empty: '', none: undefined, flag: true, off: false })).toBe(
      '?q=tiramisu&page=2&flag=1&off=0',
    )
  })
})

/** Erreur rejetée par l'appel (le test échoue si l'appel réussit). */
async function failure(call: Promise<unknown>): Promise<ApiError> {
  return call.then(
    () => {
      throw new Error('Un échec était attendu.')
    },
    (e: unknown) => e as ApiError,
  )
}

/** Gestion des erreurs du client API (20/11/2026) : toujours un message utile, jamais de texte technique. */
describe('client API : messages d’erreur', () => {
  const reply = (status: number, body: string, headers: Record<string, string> = {}) =>
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status, headers })))

  it('réponse non JSON (nginx en 502) : message d’indisponibilité et référence', async () => {
    reply(502, '<html>Bad Gateway</html>', { 'X-Request-Id': 'abc-123' })
    const error = await failure(api.get('/config'))
    expect(error).toBeInstanceOf(ApiError)
    expect(error.message).toBe('Le service est momentanément indisponible. Réessayez dans quelques instants.')
    expect(error.requestId).toBe('abc-123')
  })

  it('message du serveur conservé et délai d’attente ajouté en cas de limitation (429)', async () => {
    reply(
      429,
      JSON.stringify({
        error: {
          code: 'too_many_requests',
          message: 'Trop de requêtes, veuillez patienter.',
          details: { retry_after: 42 },
        },
      }),
    )
    const error = await failure(api.get('/config'))
    expect(errorMessage(error)).toBe('Trop de requêtes, veuillez patienter. Réessayez dans 42 s.')
  })

  it('délai dépassé : message de connexion lente', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new DOMException('signal timed out', 'TimeoutError')))
    const error = await failure(api.get('/config'))
    expect(error.code).toBe('timeout')
    expect(error.message).toMatch(/connexion est trop lente/)
  })

  it('annulation volontaire : pas de message d’erreur pour l’utilisateur', async () => {
    const controller = new AbortController()
    controller.abort()
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new DOMException('aborted', 'AbortError')))
    const error = await failure(api.get('/config', { signal: controller.signal }))
    expect(error).not.toBeInstanceOf(ApiError)
    expect(error.name).toBe('AbortError')
  })

  it('erreur JavaScript : texte technique jamais affiché', () => {
    expect(errorMessage(new TypeError("Cannot read properties of undefined (reading 'x')"))).toBe(
      'Une erreur inattendue est survenue. Rechargez la page puis réessayez.',
    )
  })
})
