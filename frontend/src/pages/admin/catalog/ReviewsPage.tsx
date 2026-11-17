import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Star, X } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Card, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Skeleton } from '@/components/ui/Feedback'
import { api, toQuery } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatDateTime } from '@/lib/format'
import { type LaravelPage, Pagination } from './shared'

interface Review {
  id: string
  author_name: string
  stars: number
  comment: string | null
  moderation_status: 'pending' | 'approved' | 'rejected'
  product: { id: string; name: string } | null
  order: { id: string; reference: string } | null
  created_at: string
}

const TABS = [
  { value: 'pending', label: 'À modérer' },
  { value: 'approved', label: 'Publiés' },
  { value: 'rejected', label: 'Refusés' },
  { value: '', label: 'Tous' },
]
const LABELS = { pending: 'À modérer', approved: 'Publié', rejected: 'Refusé' }

export function ReviewsPage() {
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('pending')
  const [page, setPage] = useState(1)
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'reviews', status, page],
    queryFn: () => api.get<LaravelPage<Review>>(`/admin/reviews${toQuery({ status, page })}`),
  })
  const moderate = useMutation({
    mutationFn: ({ id, moderation_status }: { id: string; moderation_status: 'approved' | 'rejected' }) => api.post(`/admin/reviews/${id}/moderate`, { moderation_status }),
    onSuccess: (_, v) => {
      toast.success(v.moderation_status === 'approved' ? 'Avis publié sur la fiche produit' : 'Avis refusé')
      queryClient.invalidateQueries({ queryKey: ['admin', 'reviews'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <div>
      <PageHeader eyebrow="Commerce" title="Avis clients" description="Seuls les avis approuvés apparaissent sur les fiches produits. Un avis n'est possible qu'après livraison." />
      <div className="mb-5 flex flex-wrap gap-2" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.value}
            role="tab"
            aria-selected={status === t.value}
            type="button"
            onClick={() => {
              setStatus(t.value)
              setPage(1)
            }}
            className={cn('min-h-11 rounded-full px-4 text-sm font-medium', status === t.value ? 'bg-cocoa-700 text-cream-50' : 'border border-cocoa-100 hover:bg-cream-200')}
          >
            {t.label}
          </button>
        ))}
      </div>
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : isLoading ? (
        <Skeleton className="h-40" />
      ) : !data?.data.length ? (
        <EmptyState icon={<Star />} title="Aucun avis" description="Aucun avis dans cette catégorie." />
      ) : (
        <ul className="space-y-3">
          {data.data.map((r) => (
            <li key={r.id}>
              <Card className="p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="font-semibold">
                      {r.author_name} · <span role="img" aria-label={`${r.stars} étoiles sur 5`}>{'★'.repeat(r.stars)}<span className="text-cocoa-100">{'★'.repeat(5 - r.stars)}</span></span>
                    </p>
                    <p className="text-xs text-cocoa-500">
                      {r.product?.name ?? 'Produit supprimé'} ·{' '}
                      {r.order ? (
                        <Link className="underline" to={`/admin/commandes/${r.order.id}`}>
                          {r.order.reference}
                        </Link>
                      ) : (
                        '—'
                      )}{' '}
                      · {formatDateTime(r.created_at)}
                    </p>
                  </div>
                  <StatusPill status={r.moderation_status === 'approved' ? 'published' : r.moderation_status === 'rejected' ? 'rejected' : 'pending'} label={LABELS[r.moderation_status]} />
                </div>
                <p className="mt-2 text-sm text-cocoa-800">{r.comment || <em className="text-cocoa-500">Sans commentaire</em>}</p>
                <div className="mt-3 flex gap-2">
                    {r.moderation_status !== 'approved' && (
                      <Button size="sm" icon={<Check className="size-4" />} onClick={() => moderate.mutate({ id: r.id, moderation_status: 'approved' })}>
                        Approuver
                      </Button>
                    )}
                    {r.moderation_status !== 'rejected' && (
                      <Button size="sm" variant="secondary" icon={<X className="size-4" />} onClick={() => moderate.mutate({ id: r.id, moderation_status: 'rejected' })}>
                        Refuser
                      </Button>
                    )}
                  </div>
              </Card>
            </li>
          ))}
        </ul>
      )}
      {data && <Pagination page={data.current_page} lastPage={data.last_page} total={data.total} onPage={setPage} />}
    </div>
  )
}
