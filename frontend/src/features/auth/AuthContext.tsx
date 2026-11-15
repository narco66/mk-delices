import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useMemo, type ReactNode } from 'react'
import { api, ApiError } from '@/lib/api'
import type { Me } from '@/types/api'

interface AuthContextValue {
  me: Me | null
  isLoading: boolean
  login: (email: string, password: string, remember?: boolean) => Promise<Me>
  register: (payload: Record<string, unknown>) => Promise<Me>
  logout: () => Promise<void>
  can: (permission: string) => boolean
  hasRole: (...roles: string[]) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

/** Session Sanctum (cookie HttpOnly) : l'état d'authentification est toujours relu depuis /auth/me. */
export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      try {
        return (await api.get<{ data: Me }>('/auth/me')).data
      } catch (error) {
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) return null
        throw error
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })

  const login = useCallback(
    async (email: string, password: string, remember = false) => {
      const me = (await api.post<{ data: Me }>('/auth/login', { email, password, remember })).data
      // On purge les données d'une éventuelle session précédente sans détacher l'observateur de ['me'].
      queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== 'me' })
      queryClient.setQueryData(['me'], me)
      return me
    },
    [queryClient],
  )

  const register = useCallback(
    async (payload: Record<string, unknown>) => {
      const me = (await api.post<{ data: Me }>('/auth/register', payload)).data
      queryClient.setQueryData(['me'], me)
      return me
    },
    [queryClient],
  )

  const logout = useCallback(async () => {
    await api.post('/auth/logout').catch(() => undefined)
    queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== 'me' })
    queryClient.setQueryData(['me'], null)
  }, [queryClient])

  const value = useMemo<AuthContextValue>(() => {
    const me = data ?? null
    return {
      me,
      isLoading,
      login,
      register,
      logout,
      can: (permission) => !!me && (me.roles.includes('admin') || me.permissions.includes(permission)),
      hasRole: (...roles) => !!me && roles.some((r) => me.roles.includes(r)),
    }
  }, [data, isLoading, login, register, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth doit être utilisé dans AuthProvider')
  return ctx
}
