import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Bike, ChevronRight, Clock, MapPin, Snowflake } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Badge, Card, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Skeleton } from '@/components/ui/Feedback'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatDateTime, formatSlot, formatXof } from '@/lib/format'
import type { Mission } from '@/types/api'
import { useCourierOverview } from './useCourier'

export function MissionsPage() {
  const queryClient = useQueryClient()
  const { data, isLoading, isError, error, refetch } = useCourierOverview()

  const availability = useMutation({
    mutationFn: (available: boolean) => api.put('/courier/availability', { available }),
    onSuccess: (_, available) => {
      toast.success(available ? 'Vous êtes disponible' : 'Vous êtes indisponible')
      queryClient.invalidateQueries({ queryKey: ['courier', 'missions'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (isLoading)
    return (
      <div className="space-y-3">
        <Skeleton className="h-24" />
        <Skeleton className="h-32" />
        <Skeleton className="h-32" />
      </div>
    )
  if (isError || !data) return <ErrorState error={error} onRetry={() => refetch()} />

  const available = data.partner.available

  return (
    <div className="space-y-6">
      <div>
        <p className="text-sm text-cocoa-500">Bonjour</p>
        <h1 className="text-2xl font-semibold">{data.partner.display_name}</h1>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <button
          type="button"
          role="switch"
          aria-checked={available}
          disabled={availability.isPending}
          onClick={() => availability.mutate(!available)}
          className={cn('flex min-h-20 flex-col items-start justify-center rounded-[var(--radius-card)] px-4 text-left shadow-soft transition', available ? 'bg-success-600 text-white' : 'bg-white text-cocoa-800')}
        >
          <span className="text-xs font-semibold tracking-wide uppercase opacity-80">Statut</span>
          <span className="text-lg font-semibold">{available ? 'Disponible' : 'Indisponible'}</span>
        </button>
        <Card className="flex min-h-20 flex-col justify-center px-4">
          <span className="text-xs font-semibold tracking-wide text-cocoa-500 uppercase">À percevoir</span>
          <span className="text-lg font-semibold tabular-nums">{formatXof(data.amount_due_xof)}</span>
        </Card>
      </div>

      <section aria-labelledby="active">
        <h2 id="active" className="mb-3 text-lg font-semibold">
          Missions en cours ({data.active.length})
        </h2>
        {data.active.length === 0 ? (
          <EmptyState icon={<Bike className="size-6" />} title="Aucune mission" description="Les nouvelles missions apparaîtront ici. La liste s'actualise automatiquement." />
        ) : (
          <ul className="space-y-3">
            {data.active.map((m) => (
              <li key={m.id}>
                <MissionCard mission={m} highlight={m.status === 'proposed'} />
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="history">
        <h2 id="history" className="mb-3 text-lg font-semibold">
          Historique
        </h2>
        {data.history.length === 0 ? (
          <p className="text-sm text-cocoa-500">Aucune mission terminée.</p>
        ) : (
          <ul className="space-y-2">
            {data.history.map((m) => (
              <li key={m.id}>
                <Link to={`/livreur/missions/${m.id}`} className="flex min-h-14 items-center justify-between gap-3 rounded-[var(--radius-control)] bg-white px-4 py-3 text-sm shadow-soft">
                  <span>
                    <span className="font-semibold">{m.order_reference}</span>
                    <span className="block text-xs text-cocoa-500">{formatDateTime(m.delivered_at ?? m.failed_at ?? m.assigned_at)}</span>
                  </span>
                  <span className="flex items-center gap-2">
                    {m.settled_at && <Badge tone="success">Réglée</Badge>}
                    <StatusPill status={m.status} label={m.status_label} />
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

function MissionCard({ mission: m, highlight }: { mission: Mission; highlight: boolean }) {
  return (
    <Link to={`/livreur/missions/${m.id}`} className="block">
      <Card className={cn('p-4 transition active:scale-[0.99]', highlight && 'ring-2 ring-raspberry-500')}>
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-lg font-semibold">{m.order_reference}</p>
            <p className="mt-1 flex items-center gap-1.5 text-sm text-cocoa-700">
              <Clock className="size-4" /> {formatSlot(m.slot_start_at, m.slot_end_at)}
            </p>
            <p className="mt-1 flex items-center gap-1.5 text-sm text-cocoa-700">
              <MapPin className="size-4" /> {[m.zone_name, m.district].filter(Boolean).join(' · ') || '—'}
            </p>
          </div>
          <ChevronRight className="mt-1 size-5 text-cocoa-400" aria-hidden />
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          <StatusPill status={m.status} label={m.status === 'proposed' ? 'Nouvelle mission' : m.status_label} />
          {m.requires_cold_chain && (
            <Badge tone="cocoa">
              <Snowflake className="size-3" /> Froid
            </Badge>
          )}
          {m.cash_to_collect_xof > 0 && <Badge tone="warning">À encaisser {formatXof(m.cash_to_collect_xof)}</Badge>}
        </div>
      </Card>
    </Link>
  )
}
