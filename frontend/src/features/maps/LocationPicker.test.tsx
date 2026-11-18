import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { GeoResolution } from '@/types/api'
import { LocationPicker } from './LocationPicker'

const post = vi.fn()
vi.mock('@/lib/api', () => ({
  api: { post: (...args: unknown[]) => post(...args), get: vi.fn() },
}))
// Pas de fond de carte : seul le bouton GPS et la saisie manuelle sont proposés.
vi.mock('@/hooks/usePublicData', () => ({
  usePublicConfig: () => ({
    data: {
      settings: { 'business.whatsapp_number': '+221 77 000 00 00', 'ordering.pickup_enabled': true },
      maps: { tiles_url: null, geocoding: false, attribution: '', default_center: { lat: 14.7, lng: -17.4, zoom: 12 }, bounds: {} },
    },
  }),
  whatsappLink: (n: string, text: string) => `https://wa.me/${n.replace(/\D/g, '')}?text=${encodeURIComponent(text)}`,
}))

function wrap(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>)
}

function mockGeolocation(impl: (ok: PositionCallback, ko: PositionErrorCallback) => void) {
  Object.defineProperty(navigator, 'geolocation', { configurable: true, value: { getCurrentPosition: impl } })
}

const resolution = (over: Partial<GeoResolution> = {}): GeoResolution => ({
  point: { lat: 14.7, lng: -17.45 },
  zone: { id: 'z1', name: 'Mermoz', delivery_fee_xof: 1500, min_order_xof: null },
  zone_source: 'position',
  zones_mapped: true,
  deliverable: true,
  suggestion: { label: 'Mermoz, Dakar', lat: 14.7, lng: -17.45, district: 'Mermoz', street: null },
  geocoding: 'ok',
  ...over,
})

describe('LocationPicker (géolocalisation du client)', () => {
  beforeEach(() => post.mockReset())
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it("ne demande la position qu'au clic, puis exige une confirmation explicite", async () => {
    const getCurrentPosition = vi.fn((ok: PositionCallback) => ok({ coords: { latitude: 14.7000004, longitude: -17.45, accuracy: 12 } } as GeolocationPosition))
    mockGeolocation(getCurrentPosition)
    post.mockResolvedValue({ data: resolution() })
    const onChange = vi.fn()
    const onSuggestion = vi.fn()
    wrap(<LocationPicker value={null} onChange={onChange} onSuggestion={onSuggestion} />)

    expect(getCurrentPosition).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Utiliser ma position actuelle' }))
    expect(await screen.findByText('Mermoz', { selector: 'strong' })).toBeInTheDocument()
    expect(post).toHaveBeenCalledWith('/geo/resolve', { lat: 14.7, lng: -17.45, zone_id: null })
    expect(onChange).not.toHaveBeenCalled()

    await userEvent.click(screen.getByRole('button', { name: 'Confirmer cette position' }))
    expect(onChange).toHaveBeenCalledWith({ lat: 14.7, lng: -17.45, accuracy_m: 12, source: 'gps' }, expect.objectContaining({ deliverable: true }))
    expect(onSuggestion).toHaveBeenCalledWith(expect.objectContaining({ district: 'Mermoz' }))
  })

  it('explique le refus de la localisation et laisse la saisie manuelle', async () => {
    mockGeolocation((_ok, ko) => ko({ code: 1, PERMISSION_DENIED: 1, TIMEOUT: 3, POSITION_UNAVAILABLE: 2, message: '' } as GeolocationPositionError))
    wrap(<LocationPicker value={null} onChange={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: 'Utiliser ma position actuelle' }))
    expect(await screen.findByText(/Localisation refusée/)).toBeInTheDocument()
    expect(post).not.toHaveBeenCalled()
  })

  it('refuse une position hors des zones desservies', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.9, longitude: -17.2, accuracy: 20 } } as GeolocationPosition))
    post.mockResolvedValue({ data: resolution({ zone: null, deliverable: false, suggestion: null }) })
    const onChange = vi.fn()
    wrap(<LocationPicker value={null} onChange={onChange} />)
    await userEvent.click(screen.getByRole('button', { name: 'Utiliser ma position actuelle' }))
    expect(await screen.findByText('Adresse hors de nos zones de livraison')).toBeInTheDocument()
    // Alternative proposée : WhatsApp (livraison sur devis) et retrait à l'atelier.
    expect(screen.getByRole('link', { name: 'contactez-nous sur WhatsApp' })).toHaveAttribute('href', expect.stringContaining('https://wa.me/221770000000'))
    expect(screen.getByText(/Le retrait à l'atelier reste possible/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Confirmer cette position' })).not.toBeInTheDocument()
    expect(onChange).not.toHaveBeenCalled()
  })

  it('explique une position hors du périmètre desservi', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.755, longitude: -17.39, accuracy: 20 } } as GeolocationPosition))
    post.mockResolvedValue({ data: resolution({ zone: null, deliverable: false, in_service_area: false, suggestion: null }) })
    wrap(<LocationPicker value={null} onChange={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: 'Utiliser ma position actuelle' }))
    expect(await screen.findByText(/en dehors de notre périmètre de livraison/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Confirmer cette position' })).not.toBeInTheDocument()
  })

  it('signale une position imprécise', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.7, longitude: -17.45, accuracy: 400 } } as GeolocationPosition))
    post.mockResolvedValue({ data: resolution() })
    wrap(<LocationPicker value={null} onChange={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: 'Utiliser ma position actuelle' }))
    expect(await screen.findByText(/Position approximative \(± 400 m\)/)).toBeInTheDocument()
  })

  it('permet de retirer une position confirmée', async () => {
    const onChange = vi.fn()
    wrap(<LocationPicker value={{ lat: 14.7, lng: -17.45, source: 'gps', accuracy_m: 10 }} onChange={onChange} />)
    expect(screen.getByText('Position de livraison confirmée')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Retirer' }))
    expect(onChange).toHaveBeenCalledWith(null, null)
  })
})
