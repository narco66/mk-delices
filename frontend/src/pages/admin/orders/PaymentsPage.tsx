import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { CreditCard, Webhook } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Badge, Card, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, Notice, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Select } from '@/components/ui/Form'
import { api, toQuery } from '@/lib/api'
import { formatDateTime, formatXof } from '@/lib/format'
import type { Payment } from '@/types/api'
import { Pagination, type PaginatedResponse } from './shared'
import { PAYMENT_ATTEMPT_LABELS, providerLabel } from './labels'

interface WebhookEventRow {
  id: string
  payment_id: string | null
  provider: string
  provider_event_id: string
  event_type: string
  processing_status: string
  processing_note: string | null
  received_at: string
  processed_at: string | null
}

export function PaymentsPage() {
  const [status, setStatus] = useState('')
  const [provider, setProvider] = useState('')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const params = { status, provider, q, page }

  const payments = useQuery({
    queryKey: ['admin', 'payments', params],
    queryFn: () => api.get<PaginatedResponse<Payment>>(`/admin/payments${toQuery(params)}`),
    placeholderData: keepPreviousData,
  })
  const events = useQuery({
    queryKey: ['admin', 'payments', 'events'],
    queryFn: async () => (await api.get<{ data: WebhookEventRow[] }>('/admin/payments/events')).data,
  })

  return (
    <div className="space-y-8">
      <PageHeader eyebrow="Finances" title="Paiements" description="Tentatives de paiement et rapprochement avec les prestataires." />
      <Notice>Un paiement n'est « réussi » qu'après vérification serveur auprès du prestataire (webhook authentifié + consultation de l'état). Les retours navigateur ne valident jamais un paiement.</Notice>

      <Card className="grid gap-3 p-4 sm:grid-cols-3">
        <Field label="Statut">
          {(p) => (
            <Select
              {...p}
              value={status}
              onChange={(e) => {
                setStatus(e.target.value)
                setPage(1)
              }}
            >
              <option value="">Tous</option>
              {Object.entries(PAYMENT_ATTEMPT_LABELS).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Prestataire">
          {(p) => (
            <Select
              {...p}
              value={provider}
              onChange={(e) => {
                setProvider(e.target.value)
                setPage(1)
              }}
            >
              <option value="">Tous</option>
              {['wave', 'orange_money', 'simulator', 'manual:cash', 'manual:wave_direct', 'manual:orange_money_direct', 'manual:bank_transfer', 'manual:other'].map((k) => (
                <option key={k} value={k}>
                  {providerLabel(k)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Recherche">
          {(p) => (
            <Input
              {...p}
              value={q}
              placeholder="Référence commande ou prestataire"
              onChange={(e) => {
                setQ(e.target.value)
                setPage(1)
              }}
            />
          )}
        </Field>
      </Card>

      {payments.isLoading ? (
        <Skeleton className="h-64" />
      ) : payments.isError || !payments.data ? (
        <ErrorState error={payments.error} onRetry={() => payments.refetch()} />
      ) : payments.data.data.length === 0 ? (
        <EmptyState icon={<CreditCard className="size-6" />} title="Aucun paiement" />
      ) : (
        <>
          <Card className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead className="border-b border-cocoa-100 bg-cream-100 text-left text-xs tracking-wide text-cocoa-500 uppercase">
                <tr>
                  <th className="px-4 py-3">Commande</th>
                  <th className="px-4 py-3">Prestataire</th>
                  <th className="px-4 py-3">Statut</th>
                  <th className="px-4 py-3">Créé</th>
                  <th className="px-4 py-3">Vérifié</th>
                  <th className="px-4 py-3 text-right">Montant</th>
                </tr>
              </thead>
              <tbody>
                {payments.data.data.map((p) => (
                  <tr key={p.id} className="border-b border-cocoa-100/60 last:border-0">
                    <td className="px-4 py-3">
                      <Link to={`/admin/commandes/${p.order_id}`} className="font-semibold hover:underline">
                        {p.order_reference ?? '—'}
                      </Link>
                      <div className="text-xs text-cocoa-500">{p.provider_reference ?? '—'}</div>
                    </td>
                    <td className="px-4 py-3">
                      {providerLabel(p.provider)} {p.is_simulated && <Badge>Simulé</Badge>}
                    </td>
                    <td className="px-4 py-3">
                      <StatusPill status={p.status} label={PAYMENT_ATTEMPT_LABELS[p.status] ?? p.status} />
                    </td>
                    <td className="px-4 py-3 text-xs">{formatDateTime(p.created_at)}</td>
                    <td className="px-4 py-3 text-xs">{formatDateTime(p.verified_at)}</td>
                    <td className="px-4 py-3 text-right font-semibold tabular-nums">{formatXof(p.amount_xof)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          <Pagination meta={payments.data.meta} onPage={setPage} />
        </>
      )}

      <section>
        <h2 className="mb-3 flex items-center gap-2 text-lg font-semibold">
          <Webhook className="size-5" /> Derniers événements prestataires
        </h2>
        {events.isLoading ? (
          <Skeleton className="h-40" />
        ) : events.isError ? (
          <ErrorState error={events.error} onRetry={() => events.refetch()} />
        ) : (events.data ?? []).length === 0 ? (
          <EmptyState title="Aucun webhook reçu" />
        ) : (
          <Card className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead className="border-b border-cocoa-100 bg-cream-100 text-left text-xs tracking-wide text-cocoa-500 uppercase">
                <tr>
                  <th className="px-4 py-3">Reçu</th>
                  <th className="px-4 py-3">Prestataire</th>
                  <th className="px-4 py-3">Événement</th>
                  <th className="px-4 py-3">Traitement</th>
                </tr>
              </thead>
              <tbody>
                {(events.data ?? []).map((e) => (
                  <tr key={e.id} className="border-b border-cocoa-100/60 last:border-0">
                    <td className="px-4 py-3 text-xs">{formatDateTime(e.received_at)}</td>
                    <td className="px-4 py-3">{providerLabel(e.provider)}</td>
                    <td className="px-4 py-3">
                      {e.event_type}
                      <div className="text-xs text-cocoa-500">{e.provider_event_id}</div>
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={e.processing_status === 'processed' ? 'success' : e.processing_status === 'orphan' ? 'warning' : 'neutral'}>{e.processing_status}</Badge>
                      {e.processing_note && <div className="text-xs text-cocoa-500">{e.processing_note}</div>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        )}
      </section>
    </div>
  )
}
