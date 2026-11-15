import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, Banknote, Pencil, RefreshCw, Undo2 } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader, StatusPill } from '@/components/ui/Display'
import { ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Checkbox, Field, Input, Select, Textarea } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { api, ApiError } from '@/lib/api'
import { allergenLabel, CHANNEL_LABELS, formatDateTime, formatSlot, formatXof, PAYMENT_LABELS } from '@/lib/format'
import type { AdminOrder, OrderEvent, Zone } from '@/types/api'
import { ACTION_LABELS, INCIDENT_LABELS, ORDER_STATUS_LABELS, PAYMENT_ATTEMPT_LABELS, providerLabel, REFUND_STATUS_LABELS } from './shared'

interface OrderDetailResponse {
  data: AdminOrder
  meta: { available_actions: string[] }
}

const DESTRUCTIVE = ['reject', 'cancel']

export function OrderDetailPage() {
  const { id = '' } = useParams()
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [pending, setPending] = useState<string | null>(null)
  const [modal, setModal] = useState<'details' | 'manual' | 'refund' | null>(null)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin', 'order', id],
    queryFn: () => api.get<OrderDetailResponse>(`/admin/orders/${id}`),
  })

  const refresh = (next?: OrderDetailResponse) => {
    if (next) queryClient.setQueryData(['admin', 'order', id], next)
    queryClient.invalidateQueries({ queryKey: ['admin', 'orders'] })
    queryClient.invalidateQueries({ queryKey: ['admin', 'preparation'] })
  }

  const transition = useMutation({
    mutationFn: (body: { action: string; reason?: string; packaging_checked?: boolean }) =>
      api.post<OrderDetailResponse>(`/admin/orders/${id}/transitions`, { ...body, expected_version: data?.data.version }),
    onSuccess: (res, vars) => {
      toast.success(`${ACTION_LABELS[vars.action] ?? vars.action} : effectué`)
      setPending(null)
      refresh(res)
    },
    onError: (e) => {
      toast.error(errorMessage(e))
      if (e instanceof ApiError && e.status === 409) refetch()
    },
  })

  const reconcile = useMutation({
    mutationFn: (paymentId: string) => api.post(`/admin/payments/${paymentId}/reconcile`),
    onSuccess: () => {
      toast.success('Rapprochement effectué auprès du prestataire')
      refetch()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (isLoading)
    return (
      <div className="space-y-4">
        <Skeleton className="h-12 w-72" />
        <Skeleton className="h-48" />
        <Skeleton className="h-64" />
      </div>
    )
  if (isError || !data) return <ErrorState error={error} onRetry={() => refetch()} />

  const order = data.data
  const actions = data.meta.available_actions ?? []
  const customerName = [order.customer.first_name, order.customer.last_name].filter(Boolean).join(' ') || '—'
  const editable = !order.details_locked && ['draft', 'pending_confirmation', 'accepted'].includes(order.status) && can('orders.manage')
  const due = order.total_xof - order.paid_xof
  const refundable = order.paid_xof - order.refunded_xof

  const runAction = (action: string) => {
    if (DESTRUCTIVE.includes(action) || action === 'mark_ready') setPending(action)
    else transition.mutate({ action })
  }

  return (
    <div className="space-y-6">
      <Link to="/admin/commandes" className="inline-flex items-center gap-1.5 text-sm font-medium text-cocoa-500 hover:text-cocoa-800">
        <ArrowLeft className="size-4" /> Retour aux commandes
      </Link>

      <PageHeader
        eyebrow={`Commande · ${CHANNEL_LABELS[order.channel] ?? order.channel}`}
        title={order.reference}
        description={`Créée le ${formatDateTime(order.timestamps.created_at)} · version ${order.version}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <StatusPill status={order.status} label={`Commande : ${order.status_label}`} />
            <StatusPill status={order.payment_status} label={`Paiement : ${order.payment_status_label}`} />
            {order.is_test && <Badge>Commande de test</Badge>}
          </div>
        }
      />

      {order.status === 'draft' && order.channel === 'whatsapp' && (
        <Notice tone="warning" title="Brouillon WhatsApp">
          Ce brouillon a été préparé depuis le site avant ouverture de WhatsApp. Il ne vaut ni confirmation ni paiement : complétez les coordonnées, le créneau, puis confirmez-le.
        </Notice>
      )}
      {order.status === 'delivery_failed' && (
        <Notice tone="error" title="Échec de livraison à traiter">
          Examinez l'incident ci-dessous puis réaffectez la commande depuis le tableau des livraisons ou annulez-la avec un motif.
        </Notice>
      )}

      {(actions.length > 0 || editable || can('payments.manual') || can('refunds.request')) && (
        <Card className="flex flex-wrap gap-2 p-4">
          {actions.map((a) => (
            <Button key={a} variant={DESTRUCTIVE.includes(a) ? 'secondary' : a === 'accept' || a === 'submit' ? 'accent' : 'primary'} loading={transition.isPending && transition.variables?.action === a} onClick={() => runAction(a)}>
              {ACTION_LABELS[a] ?? a}
            </Button>
          ))}
          {editable && (
            <Button variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setModal('details')}>
              Modifier les informations
            </Button>
          )}
          {can('payments.manual') && due > 0 && !['cancelled', 'rejected', 'draft'].includes(order.status) && (
            <Button variant="secondary" icon={<Banknote className="size-4" />} onClick={() => setModal('manual')}>
              Enregistrer un encaissement
            </Button>
          )}
          {can('refunds.request') && refundable > 0 && (
            <Button variant="secondary" icon={<Undo2 className="size-4" />} onClick={() => setModal('refund')}>
              Demander un remboursement
            </Button>
          )}
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card className="p-5">
            <h2 className="mb-4 text-lg font-semibold">Articles</h2>
            <ul className="divide-y divide-cocoa-100/70">
              {(order.items ?? []).map((item) => (
                <li key={item.id} className="flex flex-col gap-2 py-3 sm:flex-row sm:justify-between">
                  <div>
                    <p className="font-medium">
                      {item.qty} × {item.product_name} <span className="text-cocoa-500">– {item.variant_label}</span>
                    </p>
                    <p className="text-xs text-cocoa-500">SKU {item.sku} · préparation {item.prep_minutes} min</p>
                    {item.allergens.length > 0 && (
                      <div className="mt-1.5 flex flex-wrap gap-1">
                        {item.allergens.map((a) => (
                          <Badge key={a} tone="warning">
                            {allergenLabel(a)}
                          </Badge>
                        ))}
                      </div>
                    )}
                    {item.storage && <p className="mt-1 text-xs text-cocoa-500">Conservation : {item.storage}</p>}
                  </div>
                  <div className="text-right text-sm tabular-nums">
                    <p className="font-semibold">{formatXof(item.line_total_xof)}</p>
                    <p className="text-cocoa-500">{formatXof(item.unit_price_xof)} / u.</p>
                  </div>
                </li>
              ))}
            </ul>
            <dl className="mt-4 space-y-1.5 border-t border-cocoa-100 pt-4 text-sm">
              <Row label="Sous-total" value={formatXof(order.subtotal_xof)} />
              {order.discount_xof > 0 && <Row label={`Remise${order.promotion_code ? ` (${order.promotion_code})` : ''}`} value={`− ${formatXof(order.discount_xof)}`} />}
              <Row label="Livraison" value={formatXof(order.delivery_fee_xof)} />
              <Row label="Total" value={formatXof(order.total_xof)} strong />
              <Row label="Encaissé (vérifié)" value={formatXof(order.paid_xof)} />
              {order.refunded_xof > 0 && <Row label="Remboursé" value={formatXof(order.refunded_xof)} />}
              {due > 0 && order.status !== 'draft' && <Row label="Reste dû" value={formatXof(due)} />}
            </dl>
          </Card>

          <Card className="p-5">
            <h2 className="mb-4 text-lg font-semibold">Historique</h2>
            <Timeline events={order.events ?? []} />
          </Card>

          {can('payments.view') && (
            <Card className="p-5">
              <h2 className="mb-4 text-lg font-semibold">Paiements</h2>
              {(order.payments ?? []).length === 0 ? (
                <p className="text-sm text-cocoa-500">Aucune tentative de paiement.</p>
              ) : (
                <ul className="space-y-3">
                  {(order.payments ?? []).map((p) => (
                    <li key={p.id} className="rounded-[var(--radius-control)] border border-cocoa-100 p-3 text-sm">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <span className="font-medium">{providerLabel(p.provider)}</span>
                          <StatusPill status={p.status} label={PAYMENT_ATTEMPT_LABELS[p.status] ?? p.status} />
                          {p.is_simulated && <Badge>Simulé</Badge>}
                        </div>
                        <span className="font-semibold tabular-nums">{formatXof(p.amount_xof)}</span>
                      </div>
                      <p className="mt-1 text-xs text-cocoa-500">
                        Réf. prestataire : {p.provider_reference ?? '—'} · créé {formatDateTime(p.created_at)}
                        {p.verified_at && ` · vérifié ${formatDateTime(p.verified_at)}`}
                        {p.last_checked_at && ` · dernière vérification ${formatDateTime(p.last_checked_at)}`}
                      </p>
                      {p.is_manual && p.provider_status && <p className="mt-1 text-xs text-cocoa-500">Motif : {p.provider_status}</p>}
                      {p.events && p.events.length > 0 && (
                        <p className="mt-1 text-xs text-cocoa-500">
                          Webhooks : {p.events.map((e) => `${e.event_type} (${e.processing_status})`).join(', ')}
                        </p>
                      )}
                      {['initiated', 'pending'].includes(p.status) && !p.is_manual && (
                        <Button size="sm" variant="secondary" className="mt-2" icon={<RefreshCw className="size-4" />} loading={reconcile.isPending && reconcile.variables === p.id} onClick={() => reconcile.mutate(p.id)}>
                          Rapprocher
                        </Button>
                      )}
                    </li>
                  ))}
                </ul>
              )}
              {(order.refunds ?? []).length > 0 && (
                <>
                  <h3 className="mt-5 mb-2 font-semibold">Remboursements</h3>
                  <ul className="space-y-2 text-sm">
                    {(order.refunds ?? []).map((r) => (
                      <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-[var(--radius-control)] bg-cream-100 px-3 py-2">
                        <span>
                          {formatXof(r.amount_xof)} · {r.reason}
                        </span>
                        <span className="flex items-center gap-2">
                          <StatusPill status={r.status} label={REFUND_STATUS_LABELS[r.status] ?? r.status} />
                          <Link to="/admin/remboursements" className="text-xs font-medium text-raspberry-600 hover:underline">
                            Traiter
                          </Link>
                        </span>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </Card>
          )}
        </div>

        <div className="space-y-6">
          <Card className="space-y-3 p-5 text-sm">
            <h2 className="text-lg font-semibold">Client & livraison</h2>
            <Info label="Destinataire" value={customerName} />
            <Info label="Téléphone" value={order.customer.phone ? <a href={`tel:${order.customer.phone}`} className="text-raspberry-600 hover:underline">{order.customer.phone}</a> : '—'} />
            {order.customer.email && <Info label="Email" value={order.customer.email} />}
            <Info label="Mode" value={order.fulfillment_type === 'pickup' ? 'Retrait sur place' : 'Livraison'} />
            {order.address && (
              <Info
                label="Adresse"
                value={[order.address.zone_name, order.address.district, order.address.street, order.address.landmark ? `Repère : ${order.address.landmark}` : null].filter(Boolean).join(' · ') || '—'}
              />
            )}
            <Info label="Créneau" value={formatSlot(order.slot_start_at, order.slot_end_at)} />
            <Info label="Moyen de paiement" value={order.payment_method ? (PAYMENT_LABELS[order.payment_method] ?? order.payment_method) : 'Non choisi'} />
            {order.customer_notes && <Info label="Consignes client" value={order.customer_notes} />}
            {order.internal_notes && <Info label="Notes internes" value={order.internal_notes} />}
            {order.details_locked && <p className="text-xs text-cocoa-500">Coordonnées figées depuis le début de la préparation.</p>}
            {order.customer_id && can('customers.view') && (
              <Link to={`/admin/clients/${order.customer_id}`} className="inline-block text-sm font-medium text-raspberry-600 hover:underline">
                Fiche client →
              </Link>
            )}
          </Card>

          <Card className="p-5">
            <h2 className="mb-3 text-lg font-semibold">Missions de livraison</h2>
            {(order.assignments ?? []).length === 0 ? (
              <p className="text-sm text-cocoa-500">Aucune mission pour le moment.</p>
            ) : (
              <ul className="space-y-3 text-sm">
                {(order.assignments ?? []).map((a) => (
                  <li key={a.id} className="rounded-[var(--radius-control)] border border-cocoa-100 p-3">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-medium">{a.partner?.name ?? 'Livreur'}</span>
                      <StatusPill status={a.status} label={a.status_label} />
                    </div>
                    <p className="mt-1 text-xs text-cocoa-500">Affectée {formatDateTime(a.assigned_at)}</p>
                    {a.delivered_at && (
                      <p className="mt-1 text-xs">
                        Livrée {formatDateTime(a.delivered_at)} · preuve : {a.proof_type} « {a.proof_reference} »
                      </p>
                    )}
                    {a.refusal_reason && <p className="mt-1 text-xs">Refus : {a.refusal_reason}</p>}
                    {a.cancel_reason && <p className="mt-1 text-xs">Retirée : {a.cancel_reason}</p>}
                    {a.failed_at && (
                      <div className="mt-2 rounded bg-error-50 p-2 text-xs text-error-600">
                        <p className="flex items-center gap-1 font-semibold">
                          <AlertTriangle className="size-3.5" /> {INCIDENT_LABELS[a.incident_reason ?? ''] ?? a.incident_reason} · {formatDateTime(a.failed_at)}
                        </p>
                        {a.incident_note && <p>{a.incident_note}</p>}
                        <p>
                          État produit : {a.product_condition ?? '—'} · contact tenté : {a.contact_attempted ? 'oui' : 'non'}
                        </p>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
            {can('deliveries.view') && ['ready', 'delivery_failed', 'assigned', 'picked_up'].includes(order.status) && (
              <Link to="/admin/livraisons" className="mt-3 inline-block text-sm font-medium text-raspberry-600 hover:underline">
                Ouvrir le tableau des livraisons →
              </Link>
            )}
          </Card>
        </div>
      </div>

      <ReasonModal
        open={pending === 'reject' || pending === 'cancel'}
        action={pending ?? ''}
        paid={order.paid_xof - order.refunded_xof > 0}
        loading={transition.isPending}
        onClose={() => setPending(null)}
        onConfirm={(reason) => transition.mutate({ action: pending!, reason })}
      />
      <ReadyModal open={pending === 'mark_ready'} loading={transition.isPending} onClose={() => setPending(null)} onConfirm={() => transition.mutate({ action: 'mark_ready', packaging_checked: true })} />
      {modal === 'details' && <DetailsModal order={order} onClose={() => setModal(null)} onSaved={(res) => refresh(res)} />}
      {modal === 'manual' && <ManualPaymentModal orderId={order.id} due={due} onClose={() => setModal(null)} onSaved={() => refetch()} />}
      {modal === 'refund' && <RefundRequestModal orderId={order.id} max={refundable} onClose={() => setModal(null)} onSaved={() => refetch()} />}
    </div>
  )
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
  return (
    <div className={strong ? 'flex justify-between text-base font-semibold' : 'flex justify-between text-cocoa-700'}>
      <dt>{label}</dt>
      <dd className="tabular-nums">{value}</dd>
    </div>
  )
}

function Info({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium tracking-wide text-cocoa-500 uppercase">{label}</p>
      <div className="mt-0.5 text-cocoa-900">{value}</div>
    </div>
  )
}

function eventTitle(e: OrderEvent): string {
  if (e.type === 'status') return `${e.from_status ? (ORDER_STATUS_LABELS[e.from_status] ?? e.from_status) + ' → ' : ''}${ORDER_STATUS_LABELS[e.to_status ?? ''] ?? e.to_status}`
  const name = (e.metadata?.event as string | undefined) ?? e.type
  const labels: Record<string, string> = {
    'payment.initiated': 'Session de paiement créée',
    'payment.confirmed': 'Paiement confirmé par le prestataire',
    'payment.failed': 'Paiement non abouti',
    'payment.amount_mismatch': 'Montant reçu incohérent (non validé)',
    'payment.manual_recorded': 'Encaissement manuel enregistré',
    'refund.requested': 'Remboursement demandé',
    'refund.approved': 'Remboursement approuvé',
    'refund.rejected': 'Remboursement rejeté',
    'refund.completed': 'Remboursement effectué',
    'delivery.proposed': 'Mission proposée au livreur',
    'delivery.refused': 'Mission refusée par le livreur',
    'delivery.cancelled': 'Mission retirée',
    details: 'Informations modifiées',
  }
  return labels[name] ?? name
}

function Timeline({ events }: { events: OrderEvent[] }) {
  if (events.length === 0) return <p className="text-sm text-cocoa-500">Aucun événement.</p>
  return (
    <ol className="relative space-y-4 border-l-2 border-cocoa-100 pl-5">
      {events.map((e) => (
        <li key={e.id} className="relative">
          <span className="absolute top-1.5 -left-[27px] size-3 rounded-full border-2 border-white bg-caramel-500" aria-hidden />
          <p className="text-sm font-medium">{eventTitle(e)}</p>
          <p className="text-xs text-cocoa-500">
            {formatDateTime(e.occurred_at)} · {e.actor?.name ?? (e.actor_label === 'system' ? 'Système / client' : e.actor_label)}
          </p>
          {e.reason && <p className="mt-0.5 text-sm text-cocoa-700">« {e.reason} »</p>}
          {e.metadata && typeof e.metadata.amount_xof === 'number' && <p className="text-xs text-cocoa-500">Montant : {formatXof(e.metadata.amount_xof)}</p>}
        </li>
      ))}
    </ol>
  )
}

function ReasonModal({ open, action, paid, loading, onClose, onConfirm }: { open: boolean; action: string; paid: boolean; loading: boolean; onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string>()
  return (
    <Modal
      open={open}
      onOpenChange={(o) => !o && onClose()}
      title={action === 'reject' ? 'Refuser la commande' : 'Annuler la commande'}
      description="Le motif est obligatoire et sera conservé dans l'historique."
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Retour
          </Button>
          <Button
            variant="danger"
            loading={loading}
            onClick={() => {
              if (reason.trim().length < 3) return setError('Indiquez un motif (3 caractères minimum).')
              onConfirm(reason.trim())
            }}
          >
            Confirmer
          </Button>
        </div>
      }
    >
      {paid && (
        <Notice tone="warning" className="mb-4">
          Cette commande a été encaissée : une demande de remboursement sera ouverte automatiquement (validation par une seconde personne).
        </Notice>
      )}
      <Field label="Motif" required error={error}>
        {(p) => <Textarea {...p} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />}
      </Field>
    </Modal>
  )
}

function ReadyModal({ open, loading, onClose, onConfirm }: { open: boolean; loading: boolean; onClose: () => void; onConfirm: () => void }) {
  const [checked, setChecked] = useState(false)
  return (
    <Modal
      open={open}
      onOpenChange={(o) => !o && onClose()}
      title="Marquer la commande prête"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Retour
          </Button>
          <Button disabled={!checked} loading={loading} onClick={onConfirm}>
            Confirmer
          </Button>
        </div>
      }
    >
      <Checkbox checked={checked} onChange={(e) => setChecked(e.target.checked)} label="J'ai contrôlé le conditionnement, les quantités et la conservation au froid adaptée." />
    </Modal>
  )
}

const detailsSchema = z.object({
  first_name: z.string().trim().min(2, 'Prénom requis'),
  last_name: z.string().trim().optional(),
  phone: z.string().trim().min(9, 'Téléphone requis'),
  email: z.union([z.literal(''), z.string().trim().email('Email invalide')]),
  zone_id: z.string(),
  district: z.string().trim(),
  street: z.string().trim().optional(),
  landmark: z.string().trim().optional(),
  slot: z.string(),
  internal_notes: z.string().optional(),
  reason: z.string().optional(),
})
type DetailsForm = z.infer<typeof detailsSchema>

function toLocalInput(iso: string | null): string {
  // Dakar = UTC+0 : la valeur locale correspond à l'heure UTC.
  return iso ? iso.slice(0, 16) : ''
}

function DetailsModal({ order, onClose, onSaved }: { order: AdminOrder; onClose: () => void; onSaved: (res: OrderDetailResponse) => void }) {
  const { data: zones } = useQuery({ queryKey: ['zones'], queryFn: async () => (await api.get<{ data: Zone[] }>('/delivery/zones')).data })
  const { register, handleSubmit, formState, setError } = useForm<DetailsForm>({
    resolver: zodResolver(detailsSchema),
    defaultValues: {
      first_name: order.customer.first_name ?? '',
      last_name: order.customer.last_name ?? '',
      phone: order.customer.phone ?? '',
      email: order.customer.email ?? '',
      zone_id: order.zone?.id ?? '',
      district: order.address?.district ?? '',
      street: order.address?.street ?? '',
      landmark: order.address?.landmark ?? '',
      slot: toLocalInput(order.slot_start_at),
      internal_notes: order.internal_notes ?? '',
      reason: '',
    },
  })
  const save = useMutation({
    mutationFn: (v: DetailsForm) =>
      api.patch<OrderDetailResponse>(`/admin/orders/${order.id}/details`, {
        customer: { first_name: v.first_name, last_name: v.last_name || null, phone: v.phone, email: v.email || null },
        ...(v.zone_id && v.district ? { delivery: { zone_id: v.zone_id, district: v.district, street: v.street || null, landmark: v.landmark || null } } : {}),
        ...(v.slot && v.slot !== toLocalInput(order.slot_start_at) ? { slot_start_at: `${v.slot}:00Z` } : {}),
        internal_notes: v.internal_notes || null,
        reason: v.reason || null,
        expected_version: order.version,
      }),
    onSuccess: (res) => {
      toast.success('Informations mises à jour')
      onSaved(res)
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        Object.entries(e.fieldErrors).forEach(([k, msgs]) => setError(k.replace('customer.', '').replace('delivery.', '') as keyof DetailsForm, { message: msgs[0] }))
      }
      toast.error(errorMessage(e))
    },
  })
  const err = formState.errors

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title="Modifier les informations"
      description="Possible jusqu'au début de la préparation. Toute modification est journalisée."
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button loading={save.isPending} onClick={handleSubmit((v) => save.mutate(v))}>
            Enregistrer
          </Button>
        </div>
      }
    >
      <form className="grid gap-4 sm:grid-cols-2" onSubmit={handleSubmit((v) => save.mutate(v))}>
        <Field label="Prénom" required error={err.first_name?.message}>{(p) => <Input {...p} {...register('first_name')} />}</Field>
        <Field label="Nom" error={err.last_name?.message}>{(p) => <Input {...p} {...register('last_name')} />}</Field>
        <Field label="Téléphone" required error={err.phone?.message}>{(p) => <Input {...p} type="tel" {...register('phone')} />}</Field>
        <Field label="Email" error={err.email?.message}>{(p) => <Input {...p} type="email" {...register('email')} />}</Field>
        {order.fulfillment_type === 'delivery' && (
          <>
            <Field label="Zone" error={err.zone_id?.message}>
              {(p) => (
                <Select {...p} {...register('zone_id')}>
                  <option value="">—</option>
                  {(zones ?? []).map((z) => (
                    <option key={z.id} value={z.id}>
                      {z.name} ({formatXof(z.delivery_fee_xof)})
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            <Field label="Quartier" error={err.district?.message}>{(p) => <Input {...p} {...register('district')} />}</Field>
            <Field label="Rue / immeuble" error={err.street?.message}>{(p) => <Input {...p} {...register('street')} />}</Field>
            <Field label="Repère" error={err.landmark?.message}>{(p) => <Input {...p} {...register('landmark')} />}</Field>
          </>
        )}
        <Field label="Début du créneau (heure de Dakar)" error={err.slot?.message} hint="Le créneau doit être ouvert et non saturé.">
          {(p) => <Input {...p} type="datetime-local" step={900} {...register('slot')} />}
        </Field>
        <Field label="Motif de la modification" error={err.reason?.message}>{(p) => <Input {...p} {...register('reason')} />}</Field>
        <Field label="Notes internes" className="sm:col-span-2" error={err.internal_notes?.message}>{(p) => <Textarea {...p} {...register('internal_notes')} />}</Field>
      </form>
    </Modal>
  )
}

const amountSchema = (max: number) => ({
  amount: z
    .string()
    .regex(/^\d+$/, 'Montant entier en FCFA')
    .refine((v) => Number(v) >= 1 && Number(v) <= max, `Entre 1 et ${max} FCFA`),
  reason: z.string().trim().min(5, '5 caractères minimum'),
})

function ManualPaymentModal({ orderId, due, onClose, onSaved }: { orderId: string; due: number; onClose: () => void; onSaved: () => void }) {
  const schema = z.object({ ...amountSchema(due), method: z.enum(['cash', 'wave_direct', 'orange_money_direct', 'bank_transfer', 'other']) })
  const { register, handleSubmit, formState } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { amount: String(due), method: 'cash', reason: '' } })
  const save = useMutation({
    mutationFn: (v: z.infer<typeof schema>) => api.post(`/admin/orders/${orderId}/payments/manual`, { amount_xof: Number(v.amount), method: v.method, reason: v.reason }),
    onSuccess: () => {
      toast.success('Encaissement enregistré')
      onSaved()
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title="Enregistrer un encaissement"
      description="Réservé aux encaissements effectivement reçus (espèces, transfert vérifié…). Motif et auteur sont journalisés."
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button loading={save.isPending} onClick={handleSubmit((v) => save.mutate(v))}>
            Enregistrer
          </Button>
        </div>
      }
    >
      <div className="grid gap-4">
        <Field label="Montant (FCFA)" required error={formState.errors.amount?.message}>{(p) => <Input {...p} inputMode="numeric" {...register('amount')} />}</Field>
        <Field label="Moyen" required>
          {(p) => (
            <Select {...p} {...register('method')}>
              <option value="cash">Espèces</option>
              <option value="wave_direct">Wave (transfert direct)</option>
              <option value="orange_money_direct">Orange Money (transfert direct)</option>
              <option value="bank_transfer">Virement bancaire</option>
              <option value="other">Autre</option>
            </Select>
          )}
        </Field>
        <Field label="Justification" required error={formState.errors.reason?.message}>{(p) => <Textarea {...p} {...register('reason')} placeholder="Ex. espèces remises par le livreur Démo A" />}</Field>
      </div>
    </Modal>
  )
}

function RefundRequestModal({ orderId, max, onClose, onSaved }: { orderId: string; max: number; onClose: () => void; onSaved: () => void }) {
  const schema = z.object(amountSchema(max))
  const { register, handleSubmit, formState } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { amount: String(max), reason: '' } })
  const save = useMutation({
    mutationFn: (v: z.infer<typeof schema>) => api.post(`/admin/orders/${orderId}/refunds`, { amount_xof: Number(v.amount), reason: v.reason }),
    onSuccess: () => {
      toast.success('Demande de remboursement créée — en attente de validation')
      onSaved()
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title="Demander un remboursement"
      description={`Maximum remboursable : ${formatXof(max)}. Une autre personne habilitée devra l'approuver.`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button loading={save.isPending} onClick={handleSubmit((v) => save.mutate(v))}>
            Envoyer la demande
          </Button>
        </div>
      }
    >
      <div className="grid gap-4">
        <Field label="Montant (FCFA)" required error={formState.errors.amount?.message}>{(p) => <Input {...p} inputMode="numeric" {...register('amount')} />}</Field>
        <Field label="Motif" required error={formState.errors.reason?.message}>{(p) => <Textarea {...p} {...register('reason')} />}</Field>
      </div>
    </Modal>
  )
}
