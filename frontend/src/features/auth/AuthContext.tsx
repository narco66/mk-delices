import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useMemo, type ReactNode } from 'react'
import { api, ApiError } from '@/lib/api'
import { orderStorage } from '@/lib/storage'
import type { Me } from '@/types/api'

interface AuthContextValue {
  me: Me | null
  isLoading: boolean
  /** Identifiant : adresse email ou numéro de téléphone. */
  login: (identifier: string, password: string, remember?: boolean) => Promise<Me>
  register: (payload: Record<string, unknown>) => Promise<Me>
  /** forgetDevice : efface aussi les liens de suivi mémorisés sur cet appareil (appareil partagé). */
  logout: (options?: { forgetDevice?: boolean }) => Promise<void>
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
    async (identifier: string, password: string, remember = false) => {
      const me = (await api.post<{ data: Me }>('/auth/login', { login: identifier, password, remember })).data
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

  const logout = useCallback(async (options?: { forgetDevice?: boolean }) => {
    await api.post('/auth/logout').catch(() => undefined)
    // Audit W-06 : le panier est vidé par CartProvider à la fin de session ; les liens de suivi sur demande.
    if (options?.forgetDevice) orderStorage.clear()
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
