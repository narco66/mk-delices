import * as Dialog from '@radix-ui/react-dialog'
import { Minus, Plus, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'
import { formatXof } from '@/lib/format'

export function Price({ amount, compareAt, className }: { amount: number | null | undefined; compareAt?: number | null; className?: string }) {
  return (
    <span className={cn('inline-flex items-baseline gap-2', className)}>
      <span className="font-semibold tabular-nums">{formatXof(amount)}</span>
      {compareAt && amount !== null && amount !== undefined && compareAt > amount && (
        <s className="text-sm text-cocoa-300 tabular-nums">{formatXof(compareAt)}</s>
      )}
    </span>
  )
}

type BadgeTone = 'neutral' | 'cocoa' | 'raspberry' | 'caramel' | 'success' | 'warning' | 'error'
const badgeTones: Record<BadgeTone, string> = {
  neutral: 'bg-cream-200 text-cocoa-700',
  cocoa: 'bg-cocoa-700 text-cream-50',
  raspberry: 'bg-raspberry-50 text-raspberry-600',
  caramel: 'bg-caramel-300/40 text-caramel-600',
  success: 'bg-success-50 text-success-600',
  warning: 'bg-warning-50 text-warning-600',
  error: 'bg-error-50 text-error-600',
}

export function Badge({ tone = 'neutral', children, className }: { tone?: BadgeTone; children: ReactNode; className?: string }) {
  return <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', badgeTones[tone], className)}>{children}</span>
}

const STATUS_TONES: Record<string, BadgeTone> = {
  draft: 'neutral',
  pending_confirmation: 'warning',
  accepted: 'caramel',
  preparing: 'caramel',
  ready: 'cocoa',
  assigned: 'cocoa',
  picked_up: 'cocoa',
  delivered: 'success',
  delivery_failed: 'error',
  cancelled: 'neutral',
  rejected: 'error',
  unpaid: 'neutral',
  pending: 'warning',
  paid: 'success',
  failed: 'error',
  partially_refunded: 'caramel',
  refunded: 'neutral',
  proposed: 'warning',
  refused: 'error',
  succeeded: 'success',
  initiated: 'neutral',
  expired: 'neutral',
  requested: 'warning',
  approved: 'caramel',
  published: 'success',
  inactive: 'neutral',
  archived: 'neutral',
}

/** Statut avec libellé texte (jamais la couleur seule — UI-UX §5). */
export function StatusPill({ status, label }: { status: string; label?: string }) {
  return (
    <Badge tone={STATUS_TONES[status] ?? 'neutral'}>
      <span className="size-1.5 rounded-full bg-current" aria-hidden />
      {label ?? status}
    </Badge>
  )
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('rounded-[var(--radius-card)] border border-cocoa-100/70 bg-white shadow-soft', className)}>{children}</div>
}

interface StepperProps {
  value: number
  onChange: (value: number) => void
  min?: number
  max?: number
  disabled?: boolean
  label: string
  size?: 'sm' | 'md'
}

export function QuantityStepper({ value, onChange, min = 1, max = 20, disabled, label, size = 'md' }: StepperProps) {
  const btn = cn('grid place-items-center rounded-[var(--radius-control)] text-cocoa-700 transition hover:bg-cream-200 disabled:opacity-40', size === 'md' ? 'size-11' : 'size-9')
  return (
    <div className="inline-flex items-center rounded-[var(--radius-control)] border border-cocoa-100 bg-white" role="group" aria-label={label}>
      <button type="button" className={btn} onClick={() => onChange(value - 1)} disabled={disabled || value <= min} aria-label="Diminuer la quantité">
        <Minus className="size-4" />
      </button>
      <output className={cn('min-w-8 text-center font-semibold tabular-nums', size === 'sm' && 'text-sm')} aria-live="polite">
        {value}
      </output>
      <button type="button" className={btn} onClick={() => onChange(value + 1)} disabled={disabled || value >= max} aria-label="Augmenter la quantité">
        <Plus className="size-4" />
      </button>
    </div>
  )
}

interface ModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: string
  children: ReactNode
  footer?: ReactNode
  side?: 'center' | 'right'
}

/** Modale accessible (focus piégé, Échap pour fermer) — Radix Dialog. */
export function Modal({ open, onOpenChange, title, description, children, footer, side = 'center' }: ModalProps) {
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-cocoa-900/40 backdrop-blur-[2px] data-[state=open]:animate-fade-up" />
        <Dialog.Content
          className={cn(
            'fixed z-50 flex max-h-[92dvh] flex-col bg-cream-50 shadow-lift focus:outline-none',
            side === 'center'
              ? 'inset-x-3 bottom-3 rounded-[var(--radius-card)] sm:inset-x-auto sm:top-1/2 sm:bottom-auto sm:left-1/2 sm:w-full sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2'
              : 'inset-y-0 right-0 w-full max-w-md',
          )}
        >
          <div className="flex items-start justify-between gap-4 border-b border-cocoa-100/70 px-5 py-4">
            <div>
              <Dialog.Title className="font-display text-xl font-semibold">{title}</Dialog.Title>
              {description ? <Dialog.Description className="mt-0.5 text-sm text-cocoa-500">{description}</Dialog.Description> : <Dialog.Description className="sr-only">{title}</Dialog.Description>}
            </div>
            <Dialog.Close className="grid size-10 place-items-center rounded-full text-cocoa-500 hover:bg-cream-200" aria-label="Fermer">
              <X className="size-5" />
            </Dialog.Close>
          </div>
          <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
          {footer && <div className="border-t border-cocoa-100/70 px-5 py-4">{footer}</div>}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}

export function PageHeader({ title, description, actions, eyebrow }: { title: string; description?: ReactNode; actions?: ReactNode; eyebrow?: string }) {
  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        {eyebrow && <p className="mb-1 text-xs font-semibold tracking-[0.18em] text-raspberry-500 uppercase">{eyebrow}</p>}
        <h1 className="text-2xl font-semibold sm:text-3xl">{title}</h1>
        {description && <p className="mt-1 text-sm text-cocoa-500">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}
