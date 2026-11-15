import { AlertTriangle, CircleCheck, Info, RefreshCw } from 'lucide-react'
import type { ReactNode } from 'react'
import { ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { Button } from './Button'

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('skeleton', className)} aria-hidden />
}

export function Spinner({ label = 'Chargement…' }: { label?: string }) {
  return (
    <div role="status" className="flex items-center justify-center gap-3 py-12 text-cocoa-500">
      <span className="size-5 animate-spin rounded-full border-2 border-cocoa-100 border-t-cocoa-700" aria-hidden />
      <span className="text-sm">{label}</span>
    </div>
  )
}

interface EmptyStateProps {
  icon?: ReactNode
  title: string
  description?: ReactNode
  action?: ReactNode
  className?: string
}

export function EmptyState({ icon, title, description, action, className }: EmptyStateProps) {
  return (
    <div className={cn('flex flex-col items-center rounded-[var(--radius-card)] border border-dashed border-cocoa-100 bg-cream-50 px-6 py-12 text-center', className)}>
      {icon && <div className="mb-4 grid size-14 place-items-center rounded-full bg-cream-200 text-cocoa-500">{icon}</div>}
      <h3 className="text-lg font-semibold text-cocoa-900">{title}</h3>
      {description && <p className="mt-1.5 max-w-md text-sm text-cocoa-500">{description}</p>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  )
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return 'Une erreur inattendue est survenue.'
}

export function ErrorState({ error, onRetry, className }: { error: unknown; onRetry?: () => void; className?: string }) {
  const requestId = error instanceof ApiError ? error.requestId : undefined
  return (
    <div role="alert" className={cn('rounded-[var(--radius-card)] border border-error-600/20 bg-error-50 px-5 py-6 text-center', className)}>
      <AlertTriangle className="mx-auto mb-2 size-6 text-error-600" aria-hidden />
      <p className="font-medium text-error-600">{errorMessage(error)}</p>
      {requestId && <p className="mt-1 text-xs text-cocoa-500">Référence technique : {requestId}</p>}
      {onRetry && (
        <Button variant="secondary" size="sm" className="mt-4" onClick={onRetry} icon={<RefreshCw className="size-4" />}>
          Réessayer
        </Button>
      )}
    </div>
  )
}

type Tone = 'info' | 'success' | 'warning' | 'error'
const tones: Record<Tone, string> = {
  info: 'border-cocoa-100 bg-cocoa-50 text-cocoa-800',
  success: 'border-success-600/20 bg-success-50 text-success-600',
  warning: 'border-caramel-500/30 bg-warning-50 text-warning-600',
  error: 'border-error-600/20 bg-error-50 text-error-600',
}
const toneIcons: Record<Tone, ReactNode> = {
  info: <Info className="size-5 shrink-0" aria-hidden />,
  success: <CircleCheck className="size-5 shrink-0" aria-hidden />,
  warning: <AlertTriangle className="size-5 shrink-0" aria-hidden />,
  error: <AlertTriangle className="size-5 shrink-0" aria-hidden />,
}

export function Notice({ tone = 'info', title, children, className }: { tone?: Tone; title?: string; children?: ReactNode; className?: string }) {
  return (
    <div role={tone === 'error' ? 'alert' : 'note'} className={cn('flex gap-3 rounded-[var(--radius-control)] border px-4 py-3 text-sm', tones[tone], className)}>
      {toneIcons[tone]}
      <div>
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={cn(title && 'mt-0.5', 'opacity-95')}>{children}</div>}
      </div>
    </div>
  )
}
