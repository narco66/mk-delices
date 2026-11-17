import { afterEach, describe, expect, it } from 'vitest'
import { ORDER_TOKEN_TTL_DAYS, orderStorage } from './storage'

/** Audit M3 : les liens de suivi ne restent pas indéfiniment sur un appareil (téléphone partagé). */
describe('liens de suivi mémorisés', () => {
  afterEach(() => orderStorage.clear())

  it('oublie les liens expirés et conserve les récents', () => {
    orderStorage.save('MKD-AAAA-AAAA', 'jeton-recent')
    const old = new Date(Date.now() - (ORDER_TOKEN_TTL_DAYS + 1) * 86_400_000).toISOString()
    localStorage.setItem('mkd.orders', JSON.stringify([...orderStorage.all(), { reference: 'MKD-BBBB-BBBB', token: 'jeton-ancien', createdAt: old }]))

    expect(orderStorage.all().map((o) => o.reference)).toEqual(['MKD-AAAA-AAAA'])
    expect(orderStorage.get('MKD-BBBB-BBBB')).toBeNull()
  })

  it('« Oublier cet appareil » efface tout', () => {
    orderStorage.save('MKD-AAAA-AAAA', 'jeton')
    orderStorage.clear()
    expect(orderStorage.all()).toEqual([])
  })

  it('limite le nombre de commandes mémorisées', () => {
    for (let i = 0; i < 15; i++) orderStorage.save(`MKD-${String(i).padStart(4, '0')}-AAAA`, `jeton-${i}`)
    expect(orderStorage.all()).toHaveLength(10)
    expect(orderStorage.all()[0].reference).toBe('MKD-0014-AAAA')
  })
})
