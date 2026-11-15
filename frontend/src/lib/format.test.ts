import { describe, expect, it } from 'vitest'
import { formatSlot, formatXof, percent } from './format'

describe('format', () => {
  it('formate les montants XOF en entiers sans décimales', () => {
    expect(formatXof(12500)).toBe('12 500 FCFA')
    expect(formatXof(0)).toBe('0 FCFA')
    expect(formatXof(null)).toBe('—')
  })

  it('affiche les créneaux en heure de Dakar', () => {
    expect(formatSlot('2026-11-16T09:00:00Z', '2026-11-16T11:00:00Z')).toBe('lundi 16 novembre · 09h00–11h00')
    expect(formatSlot(null, null)).toBe('Créneau à définir')
  })

  it('formate les taux et laisse « — » quand la mesure est absente', () => {
    expect(percent(0.0712)).toBe('7,1 %')
    expect(percent(null)).toBe('—')
  })
})
