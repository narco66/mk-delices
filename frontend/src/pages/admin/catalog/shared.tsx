import { ChevronLeft, ChevronRight, X } from 'lucide-react'
import { useState, type KeyboardEvent } from 'react'
import { cn } from '@/lib/cn'

export function Pagination({ page, lastPage, total, onPage }: { page: number; lastPage: number; total?: number; onPage: (p: number) => void }) {
  if (lastPage <= 1) return total !== undefined ? <p className="mt-4 text-center text-xs text-cocoa-500">{total} élément(s)</p> : null
  return (
    <nav aria-label="Pagination" className="mt-6 flex items-center justify-center gap-3 text-sm">
      <button type="button" className="grid size-11 place-items-center rounded-full border border-cocoa-100 disabled:opacity-40" disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="Page précédente">
        <ChevronLeft className="size-4" />
      </button>
      <span>
        Page {page} / {lastPage}
        {total !== undefined && <span className="text-cocoa-500"> · {total} élément(s)</span>}
      </span>
      <button type="button" className="grid size-11 place-items-center rounded-full border border-cocoa-100 disabled:opacity-40" disabled={page >= lastPage} onClick={() => onPage(page + 1)} aria-label="Page suivante">
        <ChevronRight className="size-4" />
      </button>
    </nav>
  )
}

export function Toggle({ checked, onChange, label, description, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: string; description?: string; disabled?: boolean }) {
  return (
    <label className={cn('flex min-h-11 cursor-pointer items-start justify-between gap-4 py-1', disabled && 'cursor-not-allowed opacity-60')}>
      <span>
        <span className="block text-sm font-medium">{label}</span>
        {description && <span className="block text-xs text-cocoa-500">{description}</span>}
      </span>
      <span className="relative mt-0.5 inline-flex shrink-0">
        <input type="checkbox" role="switch" className="peer sr-only" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
        <span className="h-6 w-11 rounded-full bg-cocoa-100 transition peer-checked:bg-success-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-raspberry-500" />
        <span className="absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
      </span>
    </label>
  )
}

/** Saisie de liste en « puces » (allergènes, listes de paramètres). */
export function TagInput({ value, onChange, suggestions = [], placeholder, id, ariaLabel }: { value: string[]; onChange: (v: string[]) => void; suggestions?: string[]; placeholder?: string; id?: string; ariaLabel?: string }) {
  const [draft, setDraft] = useState('')
  const add = (raw: string) => {
    const v = raw.trim()
    if (v && !value.includes(v)) onChange([...value, v])
    setDraft('')
  }
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault()
      add(draft)
    } else if (e.key === 'Backspace' && !draft && value.length) {
      onChange(value.slice(0, -1))
    }
  }
  const remaining = suggestions.filter((s) => !value.includes(s))

  return (
    <div>
      <div className="flex min-h-11 flex-wrap items-center gap-1.5 rounded-[var(--radius-control)] border border-cocoa-100 bg-white px-2 py-1.5 focus-within:border-cocoa-500 focus-within:ring-2 focus-within:ring-raspberry-100">
        {value.map((v) => (
          <span key={v} className="inline-flex items-center gap-1 rounded-full bg-cream-200 py-1 pr-1 pl-2.5 text-sm">
            {v}
            <button type="button" onClick={() => onChange(value.filter((x) => x !== v))} className="grid size-6 place-items-center rounded-full hover:bg-cocoa-100" aria-label={`Retirer ${v}`}>
              <X className="size-3.5" />
            </button>
          </span>
        ))}
        <input
          id={id}
          aria-label={ariaLabel}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={onKey}
          onBlur={() => draft && add(draft)}
          placeholder={placeholder}
          className="min-w-32 flex-1 bg-transparent px-1 py-1 text-[15px] outline-none"
        />
      </div>
      {remaining.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {remaining.map((s) => (
            <button key={s} type="button" onClick={() => add(s)} className="rounded-full border border-dashed border-cocoa-300 px-2.5 py-1 text-xs text-cocoa-700 hover:bg-cream-200">
              + {s}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

export function CharCount({ value, max }: { value: string | null | undefined; max: number }) {
  const n = (value ?? '').length
  return <span className={cn('tabular-nums', n > max ? 'text-error-600' : 'text-cocoa-500')}>{n}/{max}</span>
}

/** Valeurs numériques optionnelles issues de champs texte. */
export interface LaravelPage<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}
