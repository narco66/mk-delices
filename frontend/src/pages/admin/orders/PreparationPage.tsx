import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChefHat, Clock, Snowflake, Undo2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Checkbox, Field, Input, Textarea } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { allergenLabel, formatSlot, relativeMinutes } from '@/lib/format'

interface PrepItem {
  product_name: string
  variant_label: string
  sku: string
  qty: number
  allergens: string[]
  storage: string | null
  prep_minutes: number
  shelf_life_hours: number | null
}

interface PrepOrder {
  id: string
  reference: string
  status: 'accepted' | 'preparing' | 'ready'
  status_label: string
  version: number
  slot_start_at: string | null
  slot_end_at: string | null
  minutes_until_slot: number | null
  priority: 'urgent' | 'normal'
  first_name: string | null
  customer_notes: string | null
  fulfillment_type: string
  requires_cold_chain: boolean
  /** Annulation du démarrage possible jusqu'à cette heure (null : plus possible). */
  undo_until: string | null
  items: PrepItem[]
}

type Board = Record<'accepted' | 'preparing' | 'ready', PrepOrder[]>

const COLUMNS: { key: keyof Board; title: string; hint: string }[] = [
  { key: 'accepted', title: 'À préparer', hint: 'Commandes acceptées' },
  { key: 'preparing', title: 'En préparation', hint: 'Production en cours' },
  { key: 'ready', title: 'Prêtes', hint: 'En attente du livreur' },
]

export function PreparationPage() {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const [date, setDate] = useState('')
  const [readyFor, setReadyFor] = useState<PrepOrder | null>(null)
  const [undoFor, setUndoFor] = useState<PrepOrder | null>(null)

  const { data, isLoading, isError, error, refetch, dataUpdatedAt } = useQuery({
    queryKey: ['admin', 'preparation', date],
    queryFn: async () => (await api.get<{ data: Board }>(`/admin/preparation${date ? `?date=${date}` : ''}`)).data,
    refetchInterval: 60_000,
  })

  const transition = useMutation({
    mutationFn: ({ order, action, reason }: { order: PrepOrder; action: string; reason?: string }) =>
      api.post(`/admin/orders/${order.id}/transitions`, { action, reason, expected_version: order.version, packaging_checked: action === 'mark_ready' ? true : undefined }),
    onSuccess: (_, { order, action }) => {
      toast.success(
        action === 'mark_ready' ? `${order.reference} est prête` : action === 'cancel_preparation' ? `${order.reference} est revenue dans « À préparer »` : `Préparation de ${order.reference} démarrée`,
      )
      setReadyFor(null)
      setUndoFor(null)
      queryClient.invalidateQueries({ queryKey: ['admin', 'preparation'] })
    },
    onError: (e) => {
      toast.error(errorMessage(e))
      // Conflit (commande modifiée entre-temps) : la fenêtre porte une version périmée → on la ferme (audit M13).
      if (e instanceof ApiError && e.status === 409) {
        setReadyFor(null)
        setUndoFor(null)
      }
      refetch()
    },
  })

  const canAct = can('preparation.manage')

  return (
    <div>
      <PageHeader
        eyebrow="Atelier"
        title="Préparation"
        description={`Ordonnée par créneau de livraison · actualisée automatiquement${dataUpdatedAt ? ` (${new Date(dataUpdatedAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })})` : ''}`}
        actions={
          <div className="flex items-end gap-2">
            <Field label="Jour de livraison">{(p) => <Input {...p} type="date" value={date} onChange={(e) => setDate(e.target.value)} />}</Field>
            {date && (
              <Button variant="ghost" onClick={() => setDate('')}>
                Tous
              </Button>
            )}
          </div>
        }
      />

      <Notice tone="info" title="Comment fonctionne ce tableau ?" className="mb-6">
        <p>
          Chaque carte est une <strong>commande acceptée</strong> par le commercial : elle arrive ici automatiquement. Faites-la avancer avec <strong>« Démarrer la préparation »</strong> puis{' '}
          <strong>« Marquer prête »</strong> (contrôle du conditionnement obligatoire) ; le responsable des livraisons l'affecte ensuite à un livreur.
        </p>
        <p className="mt-1.5">
          Démarrage fait par erreur ? <strong>« Annuler le démarrage »</strong> reste possible quelques minutes, avec un motif. Pour modifier ou annuler une commande, ouvrez-la (clic sur sa référence) ou
          prévenez le commercial : les informations de livraison sont figées dès le début de la préparation.
        </p>
      </Notice>

      {isLoading ? (
        <div className="grid gap-4 lg:grid-cols-3">
          {COLUMNS.map((c) => (
            <Skeleton key={c.key} className="h-80" />
          ))}
        </div>
      ) : isError || !data ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <div className="grid gap-4 lg:grid-cols-3">
          {COLUMNS.map((col) => (
            <section key={col.key} aria-labelledby={`col-${col.key}`} className="rounded-[var(--radius-card)] bg-cream-200/60 p-3">
              <header className="mb-3 flex items-center justify-between px-1">
                <div>
                  <h2 id={`col-${col.key}`} className="font-semibold">
                    {col.title}
                  </h2>
                  <p className="text-xs text-cocoa-500">{col.hint}</p>
                </div>
                <Badge tone="cocoa">{data[col.key].length}</Badge>
              </header>
              {data[col.key].length === 0 ? (
                <EmptyState icon={<ChefHat className="size-6" />} title="Rien ici" className="bg-white/60 py-8" />
              ) : (
                <ul className="space-y-3">
                  {data[col.key].map((o) => (
                    <li key={o.id}>
                      <Card className={cn('p-4', o.priority === 'urgent' && col.key !== 'ready' && 'ring-2 ring-raspberry-500/60')}>
                        <div className="flex items-start justify-between gap-2">
                          <Link to={`/admin/commandes/${o.id}`} className="font-semibold hover:underline">
                            {o.reference}
                          </Link>
                          <div className="flex flex-wrap justify-end gap-1">
                            {o.priority === 'urgent' && col.key !== 'ready' && <Badge tone="raspberry">Urgent</Badge>}
                            {o.requires_cold_chain && (
                              <Badge tone="cocoa">
                                <Snowflake className="size-3" /> Froid
                              </Badge>
                            )}
                            {o.fulfillment_type === 'pickup' && <Badge>Retrait</Badge>}
                          </div>
                        </div>
                        <p className="mt-1 flex items-center gap-1.5 text-xs text-cocoa-500">
                          <Clock className="size-3.5" /> {formatSlot(o.slot_start_at, o.slot_end_at)}
                          {o.minutes_until_slot !== null && <span className={cn(o.minutes_until_slot < 0 && 'font-semibold text-error-600')}>· {relativeMinutes(o.minutes_until_slot)}</span>}
                        </p>
                        {o.first_name && <p className="mt-1 text-xs text-cocoa-500">Pour : {o.first_name}</p>}
                        <ul className="mt-3 space-y-2 border-t border-cocoa-100/70 pt-3">
                          {o.items.map((i) => (
                            <li key={i.sku} className="text-sm">
                              <p className="font-medium">
                                {i.qty} × {i.product_name} <span className="text-cocoa-500">– {i.variant_label}</span>
                              </p>
                              {i.allergens.length > 0 && <p className="text-xs text-warning-600">Allergènes : {i.allergens.map(allergenLabel).join(', ')}</p>}
                              {i.storage && <p className="text-xs text-cocoa-500">{i.storage}</p>}
                            </li>
                          ))}
                        </ul>
                        {o.customer_notes && <p className="mt-3 rounded bg-warning-50 px-2 py-1.5 text-xs text-warning-600">Consignes : {o.customer_notes}</p>}
                        {canAct && col.key === 'accepted' && (
                          <Button className="mt-3 w-full" size="sm" loading={transition.isPending && transition.variables?.order.id === o.id} onClick={() => transition.mutate({ order: o, action: 'start_preparation' })}>
                            Démarrer la préparation
                          </Button>
                        )}
                        {canAct && col.key === 'preparing' && (
                          <Button className="mt-3 w-full" size="sm" variant="accent" onClick={() => setReadyFor(o)}>
                            Marquer prête
                          </Button>
                        )}
                        {canAct && col.key === 'preparing' && o.undo_until && (
                          <Button className="mt-2 w-full" size="sm" variant="ghost" icon={<Undo2 className="size-4" />} onClick={() => setUndoFor(o)}>
                            Annuler le démarrage (jusqu'à {new Date(o.undo_until).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })})
                          </Button>
                        )}
                      </Card>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          ))}
        </div>
      )}

      {readyFor && <ReadyConfirm order={readyFor} loading={transition.isPending} onClose={() => setReadyFor(null)} onConfirm={() => transition.mutate({ order: readyFor, action: 'mark_ready' })} />}
      {undoFor && <UndoConfirm order={undoFor} loading={transition.isPending} onClose={() => setUndoFor(null)} onConfirm={(reason) => transition.mutate({ order: undoFor, action: 'cancel_preparation', reason })} />}
    </div>
  )
}

function UndoConfirm({ order, loading, onClose, onConfirm }: { order: PrepOrder; loading: boolean; onClose: () => void; onConfirm: (reason: string) => void }) {
  const [reason, setReason] = useState('')
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Annuler le démarrage de ${order.reference} ?`}
      description="La commande revient dans « À préparer » et redevient modifiable. Le motif est conservé dans l'historique."
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Retour
          </Button>
          <Button disabled={reason.trim().length < 3} loading={loading} onClick={() => onConfirm(reason.trim())}>
            Annuler le démarrage
          </Button>
        </div>
      }
    >
      <Field label="Motif" hint="Ex. : mauvaise commande démarrée">
        {(p) => <Textarea {...p} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} />}
      </Field>
    </Modal>
  )
}

function ReadyConfirm({ order, loading, onClose, onConfirm }: { order: PrepOrder; loading: boolean; onClose: () => void; onConfirm: () => void }) {
  const [checked, setChecked] = useState(false)
  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={`${order.reference} prête ?`}
      description="Contrôle du conditionnement obligatoire avant mise à disposition."
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
      <Checkbox
        checked={checked}
        onChange={(e) => setChecked(e.target.checked)}
        label={order.requires_cold_chain ? 'Conditionnement, quantités et maintien au froid contrôlés.' : 'Conditionnement et quantités contrôlés.'}
      />
    </Modal>
  )
}
