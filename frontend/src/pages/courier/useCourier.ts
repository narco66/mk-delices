import { useQuery } from '@tanstack/react-query'
import { directionsUrl } from '@/features/maps/geo'
import { api } from '@/lib/api'
import type { Mission } from '@/types/api'

export interface CourierOverview {
  partner: { display_name: string; available: boolean }
  active: Mission[]
  history: Mission[]
  proof_types: string[]
  incident_reasons: string[]
  client_fault_reasons: string[]
  client_fault_min_wait_minutes: number
  failed_fee_percent: number
  delivery_code_enabled: boolean
  /** Clé publique des notifications du navigateur (null = non configurées sur le serveur). */
  push_public_key: string | null
  amount_due_xof: number
  /** Espèces encaissées pas encore remises à MK Délices. */
  cash_to_hand_over_xof: number
}

export function useCourierOverview() {
  return useQuery({
    queryKey: ['courier', 'missions'],
    queryFn: async () => (await api.get<{ data: CourierOverview }>('/courier/missions')).data,
    refetchInterval: 60_000,
  })
}

export function mapsUrl(m: Pick<Mission, 'lat' | 'lng' | 'district' | 'landmark' | 'street' | 'zone_name'>): string | null {
  if (m.lat !== null && m.lng !== null) return directionsUrl(m.lat, m.lng)
  const parts = [m.street, m.landmark, m.district, m.zone_name?.replace(/\s*\(démo\)\s*/i, ''), 'Dakar'].filter(Boolean)
  return m.street || m.landmark ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(parts.join(', '))}` : null
}
