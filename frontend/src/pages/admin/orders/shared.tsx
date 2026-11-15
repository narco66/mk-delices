import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/Button'

export interface PageMeta {
  current_page: number
  last_page: number
  total: number
  per_page?: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: PageMeta
}

export function Pagination({ meta, onPage }: { meta?: PageMeta; onPage: (page: number) => void }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <nav aria-label="Pagination" className="mt-6 flex items-center justify-between gap-3">
      <p className="text-sm text-cocoa-500">
        Page {meta.current_page} sur {meta.last_page} · {meta.total} résultat{meta.total > 1 ? 's' : ''}
      </p>
      <div className="flex gap-2">
        <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} icon={<ChevronLeft className="size-4" />}>
          Précédent
        </Button>
        <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
          Suivant
          <ChevronRight className="size-4" />
        </Button>
      </div>
    </nav>
  )
}
