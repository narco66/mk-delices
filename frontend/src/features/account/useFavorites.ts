import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/features/auth/AuthContext'
import { api } from '@/lib/api'
import type { Product } from '@/types/api'

export const FAVORITES_KEY = ['account', 'favorites'] as const

/** Favoris du client connecté (liste vide pour un visiteur ou un membre de l'équipe). */
export function useFavorites() {
  const { me } = useAuth()
  const enabled = !!me?.customer
  const query = useQuery({ queryKey: FAVORITES_KEY, enabled, staleTime: 60_000, queryFn: async () => (await api.get<{ data: Product[] }>('/account/favorites')).data })
  return { ...query, enabled, ids: new Set((query.data ?? []).map((p) => p.id)) }
}
