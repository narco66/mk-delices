import { describe, expect, it } from 'vitest'
import { formatDocumentDate, formatSlot, formatXof, percent } from './format'

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

  it("date de document : toujours avec l'année et le jour de Dakar", () => {
    expect(formatDocumentDate('2026-11-15T23:30:00Z')).toBe('15 nov. 2026')
    expect(formatDocumentDate('2027-01-02T10:00:00Z')).toContain('2027')
    expect(formatDocumentDate(null)).toBe('—')
  })
})
