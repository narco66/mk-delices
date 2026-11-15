import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Bike } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Skeleton } from '@/components/ui/Feedback'
import { Field, Select, Textarea } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { api } from '@/lib/api'
import { formatDateTime, formatSlot, formatXof } from '@/lib/format'
import type { AdminOrder, Assignment } from '@/types/api'
import { CONDITION_LABELS, INCIDENT_LABELS, TRANSPORT_LABELS } from '../orders/shared'

interface BoardPartner {
  id: string
  display_name: string
  transport_mode: string
  active: boolean
  available: boolean
  active_missions: number
  zone_ids: string[]
  zones: string[]
}

interface BoardResponse {
  data: { orders: AdminOrder[]; partners: BoardPartner[] }
}

export function DeliveryBoardPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState<Assignment | null>(null)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin', 'delivery-board'],
    queryFn: async () => (await api.get<BoardResponse>('/admin/delivery/board')).data,
    refetchInterval: 60_000,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin', 'delivery-board'] })

  if (isLoading) return <Skeleton className="h-96" />
  if (isError || !data) return <ErrorState error={error} onRetry={() => refetch()} />

  const toAssign = data.orders.filter((o) => !o.active_assignment && ['ready', 'delivery_failed'].includes(o.status))
  const inProgress = data.orders.filter((o) => o.active_assignment)
  const incidents = data.orders.filter((o) => o.status === 'delivery_failed')

  return (
    <div className="space-y-8">
      <PageHeader eyebrow="Logistique" title="Livraisons" description="Affectez les commandes prêtes aux livreurs éligibles et suivez les missions." />

      {incidents.length > 0 && (
        <section aria-labelledby="incidents">
          <h2 id="incidents" className="mb-3 flex items-center gap-2 text-lg font-semibold text-error-600">
            <AlertTriangle className="size-5" /> Incidents à traiter ({incidents.length})
          </h2>
          <ul className="grid gap-3 md:grid-cols-2">
            {incidents.map((o) => (
              <li key={o.id}>
                <Card className="border-error-600/30 p-4">
                  <div className="flex items-center justify-between gap-2">
                    <Link to={`/admin/commandes/${o.id}`} className="font-semibold hover:underline">
                      {o.reference}
                    </Link>
                    <StatusPill status={o.status} label={o.status_label} />
                  </div>
                  {o.last_incident ? (
                    <div className="mt-2 space-y-1 text-sm">
                      <p className="font-medium">{INCIDENT_LABELS[o.last_incident.incident_reason ?? ''] ?? o.last_incident.incident_reason}</p>
                      {o.last_incident.incident_note && <p className="text-cocoa-700">« {o.last_incident.incident_note} »</p>}
                      <p className="text-xs text-cocoa-500">
                        {formatDateTime(o.last_incident.failed_at)} · {o.last_incident.partner?.name} · produit : {CONDITION_LABELS[o.last_incident.product_condition ?? ''] ?? '—'} · contact tenté :{' '}
                        {o.last_incident.contact_attempted ? 'oui' : 'non'}
                      </p>
                    </div>
                  ) : (
                    <p className="mt-2 text-sm text-cocoa-500">Détails de l'incident dans la fiche commande.</p>
                  )}
                  <p className="mt-3 text-xs text-cocoa-500">Décision : réaffecter ci-dessous ou annuler depuis la fiche commande (avec motif et traitement financier).</p>
                </Card>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section aria-labelledby="to-assign">
        <h2 id="to-assign" className="mb-3 text-lg font-semibold">
          À affecter ({toAssign.length})
        </h2>
        {toAssign.length === 0 ? (
          <EmptyState icon={<Bike className="size-6" />} title="Aucune commande à affecter" description="Les commandes apparaîtront ici dès qu'elles seront prêtes." />
        ) : (
          <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            {toAssign.map((o) => (
              <li key={o.id}>
                <AssignCard order={o} partners={data.partners} canAssign={can('deliveries.assign')} onDone={invalidate} />
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="in-progress">
        <h2 id="in-progress" className="mb-3 text-lg font-semibold">
          Missions en cours ({inProgress.length})
        </h2>
        {inProgress.length === 0 ? (
          <EmptyState title="Aucune mission en cours" />
        ) : (
          <Card className="overflow-x-auto">
            <table className="w-full min-w-[680px] text-sm">
              <thead className="border-b border-cocoa-100 bg-cream-100 text-left text-xs tracking-wide text-cocoa-500 uppercase">
                <tr>
                  <th className="px-4 py-3">Commande</th>
                  <th className="px-4 py-3">Livreur</th>
                  <th className="px-4 py-3">Mission</th>
                  <th className="px-4 py-3">Créneau</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody>
                {inProgress.map((o) => {
                  const a = o.active_assignment!
                  return (
                    <tr key={o.id} className="border-b border-cocoa-100/60 last:border-0">
                      <td className="px-4 py-3">
                        <Link to={`/admin/commandes/${o.id}`} className="font-semibold hover:underline">
                          {o.reference}
                        </Link>
                        <div className="text-xs text-cocoa-500">{o.zone?.name}</div>
                      </td>
                      <td className="px-4 py-3">{a.partner?.name}</td>
                      <td className="px-4 py-3">
                        <StatusPill status={a.status} label={a.status_label} />
                        <div className="mt-1 text-xs text-cocoa-500">
                          Affectée {formatDateTime(a.assigned_at)}
                          {a.picked_up_at && ` · enlevée ${formatDateTime(a.picked_up_at)}`}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-xs">{formatSlot(o.slot_start_at, o.slot_end_at)}</td>
                      <td className="px-4 py-3 text-right">
                        {can('deliveries.assign') && ['proposed', 'accepted'].includes(a.status) && (
                          <Button size="sm" variant="secondary" onClick={() => setCancelling(a)}>
                            Retirer
                          </Button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </Card>
        )}
      </section>

      <section aria-labelledby="partners">
        <h2 id="partners" className="mb-3 text-lg font-semibold">
          Livreurs
        </h2>
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.partners.map((p) => (
            <li key={p.id}>
              <Card className="p-4 text-sm">
                <div className="flex items-center justify-between gap-2">
                  <p className="font-semibold">{p.display_name}</p>
                  <Badge tone={!p.active ? 'neutral' : p.available ? 'success' : 'warning'}>{!p.active ? 'Inactif' : p.available ? 'Disponible' : 'Indisponible'}</Badge>
                </div>
                <p className="mt-1 text-xs text-cocoa-500">
                  {TRANSPORT_LABELS[p.transport_mode] ?? p.transport_mode} · {p.active_missions} mission(s) active(s)
                </p>
                <p className="mt-1 text-xs text-cocoa-500">Zones : {p.zones.join(', ') || '—'}</p>
              </Card>
            </li>
          ))}
        </ul>
      </section>

      {cancelling && <CancelModal assignment={cancelling} onClose={() => setCancelling(null)} onDone={invalidate} />}
    </div>
  )
}

function AssignCard({ order, partners, canAssign, onDone }: { order: AdminOrder; partners: BoardPartner[]; canAssign: boolean; onDone: () => void }) {
  const eligible = partners.filter((p) => p.active && (!order.zone || p.zone_ids.includes(order.zone.id)))
  const [partnerId, setPartnerId] = useState('')
  const assign = useMutation({
    mutationFn: () => api.post('/admin/delivery/assignments', { order_id: order.id, partner_id: partnerId }),
    onSuccess: () => {
      toast.success(`Mission proposée pour ${order.reference}`)
      setPartnerId('')
      onDone()
    },
    onError: (e) => {
      toast.error(errorMessage(e))
      onDone()
    },
  })

  return (
    <Card className="p-4">
      <div className="flex items-start justify-between gap-2">
        <div>
          <Link to={`/admin/commandes/${order.id}`} className="font-semibold hover:underline">
            {order.reference}
          </Link>
          <p className="text-xs text-cocoa-500">
            {order.zone?.name ?? '—'} · {order.address?.district ?? '—'}
          </p>
        </div>
        <StatusPill status={order.status} label={order.status_label} />
      </div>
      <p className="mt-2 text-sm">{formatSlot(order.slot_start_at, order.slot_end_at)}</p>
      <p className="text-xs text-cocoa-500">
        {[order.customer.first_name, order.customer.last_name].filter(Boolean).join(' ')} · {formatXof(order.total_xof)}
        {order.payment_method === 'cash_on_delivery' && order.total_xof - order.paid_xof > 0 && ` · à encaisser ${formatXof(order.total_xof - order.paid_xof)}`}
      </p>
      {canAssign && (
        <div className="mt-3 flex gap-2">
          <Select aria-label={`Livreur pour ${order.reference}`} value={partnerId} onChange={(e) => setPartnerId(e.target.value)} className="flex-1">
            <option value="">{eligible.length ? 'Choisir un livreur…' : 'Aucun livreur éligible'}</option>
            {eligible.map((p) => (
              <option key={p.id} value={p.id}>
                {p.display_name}
                {!p.available ? ' (indisponible)' : ''} · {p.active_missions} en cours
              </option>
            ))}
          </Select>
          <Button disabled={!partnerId} loading={assign.isPending} onClick={() => assign.mutate()}>
            Affecter
          </Button>
        </div>
      )}
    </Card>
  )
}

function CancelModal({ assignment, onClose, onDone }: { assignment: Assignment; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  const cancel = useMutation({
    mutationFn: () => api.post(`/admin/delivery/assignments/${assignment.id}/cancel`, { reason }),
    onSuccess: () => {
      toast.success('Mission retirée : la commande peut être réaffectée')
      onDone()
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title="Retirer la mission"
      description={`Livreur : ${assignment.partner?.name ?? '—'}. Possible uniquement avant enlèvement.`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Retour
          </Button>
          <Button variant="danger" disabled={reason.trim().length < 3} loading={cancel.isPending} onClick={() => cancel.mutate()}>
            Retirer
          </Button>
        </div>
      }
    >
      <Field label="Motif" required>
        {(p) => <Textarea {...p} value={reason} onChange={(e) => setReason(e.target.value)} />}
      </Field>
    </Modal>
  )
}
