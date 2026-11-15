import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Category, PublicConfig, Zone } from '@/types/api'

export function usePublicConfig() {
  return useQuery({
    queryKey: ['config'],
    queryFn: async () => (await api.get<{ data: PublicConfig }>('/config')).data,
    staleTime: 10 * 60_000,
  })
}

export function useSetting<T = unknown>(key: string): T | undefined {
  const { data } = usePublicConfig()
  return data?.settings[key] as T | undefined
}

export function useZones() {
  return useQuery({
    queryKey: ['zones'],
    queryFn: async () => (await api.get<{ data: Zone[] }>('/delivery/zones')).data,
    staleTime: 10 * 60_000,
  })
}

export function useCategories() {
  return useQuery({
    queryKey: ['categories'],
    queryFn: async () => (await api.get<{ data: Category[] }>('/catalog/categories')).data,
    staleTime: 10 * 60_000,
  })
}

export function useHomeContent() {
  return useQuery({
    queryKey: ['content', 'home'],
    queryFn: async () => (await api.get<{ data: Record<string, Record<string, unknown>> }>('/content/home')).data,
    staleTime: 5 * 60_000,
  })
}

export function whatsappLink(number: string | null | undefined, text?: string): string | null {
  const digits = (number ?? '').replace(/\D/g, '')
  if (digits.length < 8) return null
  return `https://wa.me/${digits}${text ? `?text=${encodeURIComponent(text)}` : ''}`
}
