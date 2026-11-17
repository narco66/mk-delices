import { afterEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, toQuery } from './api'

afterEach(() => {
  vi.restoreAllMocks()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('client API', () => {
  it("normalise les erreurs de l'API et expose les erreurs de champ", async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ error: { code: 'validation_failed', message: 'Invalide', details: { 'customer.phone': ['Requis'] } }, request_id: 'req-1' }), { status: 422 }),
    )
    const error = (await api.get('/x').catch((e: unknown) => e)) as ApiError
    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(422)
    expect(error.fieldErrors['customer.phone']).toEqual(['Requis'])
    expect(error.requestId).toBe('req-1')
  })

  it("envoie le jeton CSRF sur les écritures et jamais d'en-tête d'authentification", async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D'
    const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: 1 }), { status: 200 }))
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
      .mockResolvedValueOnce(new Response(JSON.stringify({ error: { code: 'csrf', message: 'Expiré' } }), { status: 419 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 })) // /sanctum/csrf-cookie
      .mockResolvedValueOnce(new Response(JSON.stringify({ data: 'ok' }), { status: 200 }))
    await expect(api.post('/cart', {})).resolves.toEqual({ data: 'ok' })
    expect(spy).toHaveBeenCalledTimes(3)
    expect(String(spy.mock.calls[1][0])).toBe('/sanctum/csrf-cookie')

    // Un second 419 consécutif n'entraîne pas de boucle : l'erreur est remontée.
    spy.mockReset()
    spy
      .mockResolvedValueOnce(new Response(JSON.stringify({ error: { code: 'csrf', message: 'Expiré' } }), { status: 419 }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ error: { code: 'csrf', message: 'Expiré' } }), { status: 419 }))
    await expect(api.post('/cart', {})).rejects.toMatchObject({ status: 419 })
    expect(spy).toHaveBeenCalledTimes(3)
  })

  it('signale une erreur réseau lisible', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('Failed to fetch'))
    await expect(api.get('/x')).rejects.toMatchObject({ code: 'network_error', status: 0 })
  })

  it('construit des paramètres de requête sans valeurs vides', () => {
    expect(toQuery({ q: 'tiramisu', page: 2, empty: '', none: undefined, flag: true, off: false })).toBe('?q=tiramisu&page=2&flag=1&off=0')
  })
})
