import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { Card, PageHeader, StatusPill } from '@/components/ui/Display'
import { Field, Input } from '@/components/ui/Form'
import { EmptyState, ErrorState, Notice, Skeleton } from '@/components/ui/Feedback'
import { useAuth } from '@/features/auth/AuthContext'
import { api, toQuery } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatDateTime, formatXof, todayDakar } from '@/lib/format'
import { RankedBars, TimeseriesCharts } from './charts'
import { statusItems, topProductItems } from './chartData'
import { KpiGrid, StatTile } from './KpiGrid'
import { ZoneTable } from './ZoneTable'
import type { DashboardData, Operations, StatsSummary } from './types'

interface OpCard {
  key: keyof Operations
  label: string
  to: string
  permission: string
  alert?: boolean
}

const OP_CARDS: OpCard[] = [
  { key: 'to_confirm', label: 'À confirmer', to: '/admin/commandes?status=pending_confirmation', permission: 'orders.view', alert: true },
  { key: 'whatsapp_drafts', label: 'Brouillons WhatsApp', to: '/admin/commandes?status=draft', permission: 'orders.view' },
  { key: 'in_preparation', label: 'En préparation', to: '/admin/preparation', permission: 'preparation.view' },
  { key: 'ready_to_assign', label: 'Prêtes à affecter', to: '/admin/livraisons', permission: 'deliveries.view', alert: true },
  { key: 'deliveries_in_progress', label: 'Livraisons en cours', to: '/admin/livraisons', permission: 'deliveries.view' },
  { key: 'deliveries_failed', label: 'Livraisons échouées', to: '/admin/commandes?status=delivery_failed', permission: 'orders.view', alert: true },
  { key: 'payments_pending', label: 'Paiements en attente', to: '/admin/paiements?status=pending', permission: 'payments.view' },
  { key: 'refunds_pending', label: 'Remboursements à traiter', to: '/admin/remboursements', permission: 'payments.view', alert: true },
]

export function DashboardPage() {
  const { me, can } = useAuth()
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'dashboard'],
    queryFn: async () => (await api.get<{ data: DashboardData }>('/admin/dashboard')).data,
    refetchInterval: 60_000,
  })
  const cards = OP_CARDS.filter((c) => can(c.permission))

  return (
    <div>
      <PageHeader eyebrow="Vue générale" title={`Bonjour ${me?.name?.split(' ')[0] ?? ''}`} description="Activité en temps réel de MK Délices." />
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-8">
          <section aria-labelledby="ops-title">
            <h2 id="ops-title" className="mb-3 text-lg font-semibold">
              À traiter maintenant
            </h2>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
              {isLoading
                ? Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-28" />)
                : cards.map((c) => {
                    const value = data?.operations[c.key] ?? 0
                    return <StatTile key={c.key} label={c.label} value={value} to={c.to} tone={c.alert && value > 0 ? 'alert' : 'default'} />
                  })}
            </div>
          </section>

          {data?.last_30_days && (
            <>
              <section aria-labelledby="kpi-title">
                <div className="mb-3 flex items-end justify-between gap-2">
                  <h2 id="kpi-title" className="text-lg font-semibold">
                    30 derniers jours
                  </h2>
                  <Link to="/admin/statistiques" className="text-sm font-medium text-raspberry-600 hover:underline">
                    Statistiques détaillées
                  </Link>
                </div>
                <KpiGrid kpis={data.last_30_days.kpis} />
              </section>
              <TimeseriesCharts data={data.last_30_days.timeseries} />
              <div className="grid gap-6 lg:grid-cols-2">
                <RankedBars title="Meilleures ventes (quantités)" items={topProductItems(data.last_30_days.top_products)} empty="Aucune vente payée sur la période." />
                <RecentOrders orders={data.recent_orders ?? []} />
              </div>
            </>
          )}
        </div>
      )}
    </div>
  )
}

function RecentOrders({ orders }: { orders: NonNullable<DashboardData['recent_orders']> }) {
  return (
    <Card className="p-5">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold">Commandes récentes</h2>
        <Link to="/admin/commandes" className="text-sm font-medium text-raspberry-600 hover:underline">
          Tout voir
        </Link>
      </div>
      {orders.length === 0 ? (
        <p className="py-6 text-center text-sm text-cocoa-500">Aucune commande pour le moment.</p>
      ) : (
        <ul className="divide-y divide-cocoa-100/60">
          {orders.map((o) => (
            <li key={o.id}>
              <Link to={`/admin/commandes/${o.id}`} className="flex flex-wrap items-center justify-between gap-2 py-2.5 hover:bg-cream-50">
                <div className="min-w-0">
                  <p className="font-semibold">{o.reference}</p>
                  <p className="text-xs text-cocoa-500">
                    {o.customer.first_name ?? '—'} · {formatDateTime(o.timestamps.created_at)}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <StatusPill status={o.status} label={o.status_label} />
                  <span className="text-sm font-semibold tabular-nums">{formatXof(o.total_xof)}</span>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

const PRESETS = [
  { days: 7, label: '7 jours' },
  { days: 30, label: '30 jours' },
  { days: 90, label: '90 jours' },
]

export function StatsPage() {
  const [range, setRange] = useState({ from: todayDakar(-29), to: todayDakar() })
  const [preset, setPreset] = useState<number | 'custom'>(30)
  const invalid = range.from > range.to

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'stats', range],
    queryFn: async () => (await api.get<{ data: StatsSummary }>(`/admin/stats${toQuery(range)}`)).data,
    enabled: !invalid,
  })

  return (
    <div>
      <PageHeader eyebrow="Pilotage" title="Statistiques" description="Indicateurs calculés depuis la base de données, commandes de test exclues." />

      <div className="mb-6 flex flex-col gap-3 rounded-[var(--radius-card)] border border-cocoa-100/70 bg-white p-4 sm:flex-row sm:flex-wrap sm:items-end">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Période prédéfinie">
          {PRESETS.map((p) => (
            <button
              key={p.days}
              type="button"
              aria-pressed={preset === p.days}
              onClick={() => {
                setPreset(p.days)
                setRange({ from: todayDakar(-(p.days - 1)), to: todayDakar() })
              }}
              className={cn('min-h-11 rounded-full px-4 text-sm font-medium', preset === p.days ? 'bg-cocoa-700 text-cream-50' : 'border border-cocoa-100 hover:bg-cream-200')}
            >
              {p.label}
            </button>
          ))}
        </div>
        <Field label="Du" className="sm:w-44">
          {(p) => <Input {...p} type="date" value={range.from} max={range.to} onChange={(e) => {
                setPreset('custom')
                setRange((r) => ({ ...r, from: e.target.value }))
              }} />}
        </Field>
        <Field label="Au" className="sm:w-44" error={invalid ? 'La date de fin doit suivre la date de début.' : undefined}>
          {(p) => <Input {...p} type="date" value={range.to} min={range.from} onChange={(e) => {
                setPreset('custom')
                setRange((r) => ({ ...r, to: e.target.value }))
              }} />}
        </Field>
      </div>

      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : isLoading || !data ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      ) : (
        <div className="space-y-6">
          <KpiGrid kpis={data.kpis} />
          <TimeseriesCharts data={data.timeseries} />
          <div className="grid gap-6 lg:grid-cols-2">
            <RankedBars title="Meilleures ventes (quantités)" items={topProductItems(data.top_products)} empty="Aucune vente payée sur la période." />
            <RankedBars title="Répartition des commandes par état" items={statusItems(data.status_breakdown)} empty="Aucune commande sur la période." />
          </div>
          <ZoneTable rows={data.by_zone ?? []} />
          {data.kpis.paid_orders === 0 && data.kpis.confirmed_orders === 0 ? (
            <EmptyState title="Aucune activité sur cette période" description="Élargissez la période ou attendez les premières commandes." />
          ) : null}
          <Notice title="Définitions (PRD §2)">
            CA net = montants encaissés − remboursés des commandes payées · Panier moyen = CA net / commandes payées · Livraisons réussies = missions livrées / missions clôturées ·
            Préparation = prête − acceptée · Taux de conversion non mesuré tant qu'aucun outil d'analytics n'est validé (décision D-STATS-01). Période en heure de Dakar.
          </Notice>
        </div>
      )}
    </div>
  )
}
