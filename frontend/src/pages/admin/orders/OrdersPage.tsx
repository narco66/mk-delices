import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ClipboardList, Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { ButtonLink } from '@/components/ui/Button'
import { Badge, Card, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Select } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { api, toQuery } from '@/lib/api'
import { CHANNEL_LABELS, formatDateTime, formatSlot, formatXof } from '@/lib/format'
import type { AdminOrder } from '@/types/api'
import { Pagination, type PaginatedResponse } from './shared'
import { chip, ORDER_STATUS_LABELS, PAYMENT_STATUS_LABELS } from './labels'

const STATUS_FILTERS = ['draft', 'pending_confirmation', 'accepted', 'preparing', 'ready', 'assigned', 'picked_up', 'delivery_failed', 'delivered', 'cancelled', 'rejected']

export function OrdersPage() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('q') ?? '')
  const statuses = (params.get('status') ?? '').split(',').filter(Boolean)
  const page = Number(params.get('page') ?? 1)

  const setParam = (key: string, value: string | null) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next, { replace: true })
  }

  const toggleStatus = (s: string) => {
    const next = statuses.includes(s) ? statuses.filter((x) => x !== s) : [...statuses, s]
    setParam('status', next.join(',') || null)
  }

  const query = {
    status: params.get('status'),
    payment_status: params.get('payment_status'),
    channel: params.get('channel'),
    q: params.get('q'),
    from: params.get('from'),
    to: params.get('to'),
    slot_date: params.get('slot_date'),
    sort: params.get('sort'),
    page,
  }

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['admin', 'orders', query],
    queryFn: () => api.get<PaginatedResponse<AdminOrder>>(`/admin/orders${toQuery(query)}`),
    placeholderData: keepPreviousData,
  })

  return (
    <div>
      <PageHeader
        eyebrow="Opérations"
        title="Commandes"
        description="Toutes les commandes : statut commercial, financier et logistique."
        actions={
          can('orders.manage') ? (
            <ButtonLink to="/admin/commandes/nouvelle" icon={<Plus className="size-4" />}>
              Nouvelle commande
            </ButtonLink>
          ) : undefined
        }
      />

      <Card className="mb-6 space-y-4 p-4">
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            setParam('q', search.trim() || null)
          }}
          role="search"
        >
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-cocoa-400" aria-hidden />
            <Input className="pl-9" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Référence, prénom, téléphone…" aria-label="Rechercher une commande" />
          </div>
          <button type="submit" className="rounded-[var(--radius-control)] bg-cocoa-700 px-4 text-sm font-semibold text-cream-50">
            Rechercher
          </button>
        </form>

        <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrer par statut">
          {STATUS_FILTERS.map((s) => (
            <button key={s} type="button" className={chip(statuses.includes(s))} aria-pressed={statuses.includes(s)} onClick={() => toggleStatus(s)}>
              {ORDER_STATUS_LABELS[s]}
            </button>
          ))}
        </div>

        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <Field label="Paiement">
            {(p) => (
              <Select {...p} value={params.get('payment_status') ?? ''} onChange={(e) => setParam('payment_status', e.target.value || null)}>
                <option value="">Tous</option>
                {Object.entries(PAYMENT_STATUS_LABELS).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label="Canal">
            {(p) => (
              <Select {...p} value={params.get('channel') ?? ''} onChange={(e) => setParam('channel', e.target.value || null)}>
                <option value="">Tous</option>
                {Object.entries(CHANNEL_LABELS).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label="Créée du">{(p) => <Input {...p} type="date" value={params.get('from') ?? ''} onChange={(e) => setParam('from', e.target.value || null)} />}</Field>
          <Field label="au">{(p) => <Input {...p} type="date" value={params.get('to') ?? ''} onChange={(e) => setParam('to', e.target.value || null)} />}</Field>
          <Field label="Livraison le">{(p) => <Input {...p} type="date" value={params.get('slot_date') ?? ''} onChange={(e) => setParam('slot_date', e.target.value || null)} />}</Field>
          <Field label="Tri">
            {(p) => (
              <Select {...p} value={params.get('sort') ?? 'created_desc'} onChange={(e) => setParam('sort', e.target.value)}>
                <option value="created_desc">Plus récentes</option>
                <option value="created_asc">Plus anciennes</option>
                <option value="slot_asc">Créneau le plus proche</option>
                <option value="total_desc">Montant décroissant</option>
              </Select>
            )}
          </Field>
        </div>
      </Card>

      {isLoading ? (
        <div className="space-y-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : isError ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : !data || data.data.length === 0 ? (
        <EmptyState icon={<ClipboardList className="size-6" />} title="Aucune commande" description="Aucune commande ne correspond à ces critères." />
      ) : (
        <div className={isFetching ? 'opacity-70 transition' : 'transition'}>
          <Card className="hidden overflow-x-auto md:block">
            <table className="w-full text-sm">
              <thead className="border-b border-cocoa-100 bg-cream-100 text-left text-xs tracking-wide text-cocoa-500 uppercase">
                <tr>
                  <th className="px-4 py-3">Référence</th>
                  <th className="px-4 py-3">Client</th>
                  <th className="px-4 py-3">Créneau</th>
                  <th className="px-4 py-3">Statut</th>
                  <th className="px-4 py-3">Paiement</th>
                  <th className="px-4 py-3 text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                {data.data.map((o) => (
                  <tr key={o.id} className="border-b border-cocoa-100/60 last:border-0 hover:bg-cream-50">
                    <td className="px-4 py-3">
                      <Link to={`/admin/commandes/${o.id}`} className="font-semibold text-cocoa-800 hover:text-raspberry-600 hover:underline">
                        {o.reference}
                      </Link>
                      <div className="mt-0.5 flex items-center gap-1.5 text-xs text-cocoa-500">
                        {formatDateTime(o.timestamps.created_at)}
                        {o.channel === 'whatsapp' && <Badge tone="success">WhatsApp</Badge>}
                        {o.is_test && <Badge tone="neutral">Test</Badge>}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      {[o.customer.first_name, o.customer.last_name].filter(Boolean).join(' ') || '—'}
                      <div className="text-xs text-cocoa-500">{o.zone?.name ?? (o.fulfillment_type === 'pickup' ? 'Retrait' : '—')}</div>
                    </td>
                    <td className="px-4 py-3 text-xs">{formatSlot(o.slot_start_at, o.slot_end_at)}</td>
                    <td className="px-4 py-3">
                      <StatusPill status={o.status} label={o.status_label} />
                    </td>
                    <td className="px-4 py-3">
                      <StatusPill status={o.payment_status} label={o.payment_status_label} />
                    </td>
                    <td className="px-4 py-3 text-right font-semibold tabular-nums">{formatXof(o.total_xof)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>

          <ul className="space-y-3 md:hidden">
            {data.data.map((o) => (
              <li key={o.id}>
                <Link to={`/admin/commandes/${o.id}`} className="block">
                  <Card className="p-4 transition hover:shadow-lift">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="font-semibold">{o.reference}</p>
                        <p className="text-xs text-cocoa-500">{[o.customer.first_name, o.customer.last_name].filter(Boolean).join(' ') || '—'}</p>
                      </div>
                      <p className="font-semibold tabular-nums">{formatXof(o.total_xof)}</p>
                    </div>
                    <p className="mt-2 text-xs text-cocoa-500">{formatSlot(o.slot_start_at, o.slot_end_at)}</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                      <StatusPill status={o.status} label={o.status_label} />
                      <StatusPill status={o.payment_status} label={o.payment_status_label} />
                      {o.channel === 'whatsapp' && <Badge tone="success">WhatsApp</Badge>}
                    </div>
                  </Card>
                </Link>
              </li>
            ))}
          </ul>
          <Pagination meta={data.meta} onPage={(p) => setParam('page', String(p))} />
        </div>
      )}
    </div>
  )
}
