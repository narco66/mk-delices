import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router'
import { Spinner } from '@/components/ui/Feedback'
import { useAuth } from '@/features/auth/AuthContext'

/** Garde de route côté client — l'autorisation réelle est TOUJOURS vérifiée par l'API. */
export function RequireAuth({ children, permission, role }: { children: ReactNode; permission?: string[]; role?: string[] }) {
  const { me, isLoading, can, hasRole } = useAuth()
  const location = useLocation()
  if (isLoading) return <Spinner label="Vérification de la session…" />
  if (!me) return <Navigate to={`/connexion?redirect=${encodeURIComponent(location.pathname + location.search)}`} replace />
  if (permission && !permission.some((p) => can(p))) return <Navigate to="/acces-refuse" replace />
  if (role && !hasRole(...role)) return <Navigate to="/acces-refuse" replace />
  return <>{children}</>
}
