import { describe, expect, it } from 'vitest'
import { safeRedirect } from './redirect'

const origin = 'https://mk-delices.example'

describe('safeRedirect (audit W-07)', () => {
  it('garde les pages du site avec leurs paramètres', () => {
    expect(safeRedirect('/compte', origin)).toBe('/compte')
    expect(safeRedirect('/paiement/MKD-AB12-CD34?auto=1#haut', origin)).toBe('/paiement/MKD-AB12-CD34?auto=1#haut')
  })

  it('refuse toute sortie du site', () => {
    for (const target of ['//evil.example/x', '/\\evil.example', '/\\/evil.example', 'https://evil.example', 'javascript:alert(1)', '', null]) {
      expect(safeRedirect(target, origin)).toBeNull()
    }
  })
})
