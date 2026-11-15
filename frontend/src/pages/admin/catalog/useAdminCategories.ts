import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Category } from '@/types/api'

/** Toutes les catégories (y compris inactives) pour le back-office. */
export function useAdminCategories() {
  return useQuery({
    queryKey: ['admin', 'categories'],
    queryFn: async () => (await api.get<{ data: Category[] }>('/admin/categories')).data,
  })
}
