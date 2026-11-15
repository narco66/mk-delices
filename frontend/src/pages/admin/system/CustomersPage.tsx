import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Search, Users } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge, Card, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, Skeleton, Spinner } from '@/components/ui/Feedback'
import { Input } from '@/components/ui/Form'
import { api, toQuery } from '@/lib/api'
import { CHANNEL_LABELS, formatDateTime, formatXof } from '@/lib/format'
import type { AdminOrder } from '@/types/api'
import { type LaravelPage, Pagination } from '../catalog/shared'

interface CustomerRow {
  id: string
  first_name: string
  last_name: string | null
  phone: string
  email: string | null
  user_id: string | null
  marketing_consent_at: string | null
  is_demo: boolean
  created_at: string
  orders_count: number
  revenue_xof: number | string | null
}

interface CustomerDetail extends Omit<CustomerRow, 'orders_count' | 'revenue_xof'> {
  preferences: { favorite_flavors?: string[] } | null
  addresses: { id: string; label: string | null; district: string; street: string | null; landmark: string | null; is_default: boolean; zone: { id: string; name: string } | null }[]
  user: { id: string; email: string } | null
  orders: AdminOrder[]
}

export function CustomersPage() {
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'customers', q, page],
    queryFn: () => api.get<LaravelPage<CustomerRow>>(`/admin/customers${toQuery({ q, page })}`),
  })

  return (
    <div>
      <PageHeader eyebrow="Commerce" title="Clients" description="Clients invités et comptes. Les invités ne sont pas fusionnés par téléphone (protection des historiques)." />
      <div className="relative mb-5 max-w-md">
        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-cocoa-400" aria-hidden />
        <Input
          className="pl-9"
          placeholder="Nom, prénom ou téléphone"
          aria-label="Rechercher un client"
          value={q}
          onChange={(e) => {
            setQ(e.target.value)
            setPage(1)
          }}
        />
      </div>
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : isLoading ? (
        <Skeleton className="h-60" />
      ) : !data?.data.length ? (
        <EmptyState icon={<Users />} title="Aucun client" description="Aucun client ne correspond à la recherche." />
      ) : (
        <Card className="divide-y divide-cocoa-100/60">
          {data.data.map((c) => (
            <Link key={c.id} to={`/admin/clients/${c.id}`} className="flex flex-col gap-1 px-4 py-3 hover:bg-cream-50 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <p className="font-semibold">
                  {c.first_name} {c.last_name}
                </p>
                <p className="text-xs text-cocoa-500">
                  {c.phone} · client depuis le {formatDateTime(c.created_at, { timeStyle: undefined })}
                </p>
              </div>
              <div className="flex flex-wrap items-center gap-2 text-sm">
                {c.user_id ? <Badge tone="cocoa">Compte</Badge> : <Badge>Invité</Badge>}
                {c.marketing_consent_at && <Badge tone="success">Marketing accepté</Badge>}
                {c.is_demo && <Badge tone="warning">Démo</Badge>}
                <span className="text-cocoa-500">{c.orders_count} commande(s)</span>
                <span className="font-semibold tabular-nums">{formatXof(Number(c.revenue_xof ?? 0))}</span>
              </div>
            </Link>
          ))}
        </Card>
      )}
      {data && <Pagination page={data.current_page} lastPage={data.last_page} total={data.total} onPage={setPage} />}
    </div>
  )
}

export function CustomerDetailPage() {
  const { id } = useParams()
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'customer', id],
    queryFn: async () => (await api.get<{ data: CustomerDetail }>(`/admin/customers/${id}`)).data,
  })

  if (isLoading) return <Spinner />
  if (error || !data) return <ErrorState error={error} onRetry={() => refetch()} />

  return (
    <div>
      <Link to="/admin/clients" className="mb-3 inline-flex items-center gap-1 text-sm text-cocoa-500 hover:text-cocoa-800">
        <ArrowLeft className="size-4" /> Clients
      </Link>
      <PageHeader title={`${data.first_name} ${data.last_name ?? ''}`} description={data.user ? `Compte client · ${data.user.email}` : 'Client invité'} />
      <div className="grid gap-6 lg:grid-cols-[320px_1fr]">
        <div className="space-y-6">
          <Card className="space-y-3 p-5 text-sm">
            <h2 className="text-lg font-semibold">Profil</h2>
            <p>
              <span className="text-cocoa-500">Téléphone :</span> {data.phone}
            </p>
            <p>
              <span className="text-cocoa-500">Email :</span> {data.email ?? '—'}
            </p>
            <p>
              <span className="text-cocoa-500">Consentement marketing :</span>{' '}
              {data.marketing_consent_at ? `accordé le ${formatDateTime(data.marketing_consent_at)}` : 'non accordé'}
            </p>
            {data.preferences?.favorite_flavors?.length ? (
              <p>
                <span className="text-cocoa-500">Parfums préférés :</span> {data.preferences.favorite_flavors.join(', ')}
              </p>
            ) : null}
          </Card>
          <Card className="space-y-3 p-5">
            <h2 className="text-lg font-semibold">Adresses</h2>
            {data.addresses.length === 0 ? (
              <p className="text-sm text-cocoa-500">Aucune adresse enregistrée.</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {data.addresses.map((a) => (
                  <li key={a.id} className="rounded-[var(--radius-control)] bg-cream-100 p-3">
                    <p className="font-medium">
                      {a.label ?? 'Adresse'} {a.is_default && <Badge>Par défaut</Badge>}
                    </p>
                    <p className="text-cocoa-700">
                      {a.zone?.name} · {a.district}
                      {a.street ? ` · ${a.street}` : ''}
                    </p>
                    {a.landmark && <p className="text-xs text-cocoa-500">Repère : {a.landmark}</p>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
        <Card className="p-5">
          <h2 className="mb-4 text-lg font-semibold">Commandes ({data.orders.length})</h2>
          {data.orders.length === 0 ? (
            <p className="text-sm text-cocoa-500">Aucune commande.</p>
          ) : (
            <ul className="divide-y divide-cocoa-100/60">
              {data.orders.map((o) => (
                <li key={o.id}>
                  <Link to={`/admin/commandes/${o.id}`} className="flex flex-wrap items-center justify-between gap-2 py-3 hover:bg-cream-50">
                    <div>
                      <p className="font-semibold">{o.reference}</p>
                      <p className="text-xs text-cocoa-500">
                        {formatDateTime(o.timestamps.created_at)} · {CHANNEL_LABELS[o.channel] ?? o.channel}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <StatusPill status={o.status} label={o.status_label} />
                      <StatusPill status={o.payment_status} label={o.payment_status_label} />
                      <span className="font-semibold tabular-nums">{formatXof(o.total_xof)}</span>
                    </div>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}
