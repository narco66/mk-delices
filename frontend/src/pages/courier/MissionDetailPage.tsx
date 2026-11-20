import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Map as MapIcon, MessageCircle, Phone, Snowflake } from 'lucide-react'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button, buttonClass } from '@/components/ui/Button'
import { Card, Modal, StatusPill } from '@/components/ui/Display'
import { ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Checkbox, Field, Input, Select, Textarea } from '@/components/ui/Form'
import { api, ApiError } from '@/lib/api'
import { formatDateTime, formatSlot, formatTime, formatXof } from '@/lib/format'
import type { Mission } from '@/types/api'
import { CONDITION_LABELS, INCIDENT_LABELS, PROOF_LABELS } from '../admin/orders/labels'
import { mapsUrl, useCourierOverview } from './useCourier'

type Action = 'accept' | 'refuse' | 'release' | 'pick_up' | 'deliver' | 'fail'

export function MissionDetailPage() {
  const { id = '' } = useParams()
  const queryClient = useQueryClient()
  const overview = useCourierOverview()
  const [dialog, setDialog] = useState<Exclude<Action, 'accept'> | null>(null)

  const { data: mission, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['courier', 'mission', id],
    queryFn: async () => (await api.get<{ data: Mission }>(`/courier/missions/${id}`)).data,
    refetchInterval: 30_000, // mission retirée ou modifiée par le responsable : visible sans action
  })

  const act = useMutation({
    mutationFn: (body: Record<string, unknown>) => api.post<{ data: Mission }>(`/courier/missions/${id}/actions`, body),
    // Réseau instable : 2 nouvelles tentatives sur coupure (sans risque : le serveur refuse une action déjà faite).
    retry: (count, e) => e instanceof ApiError && e.status === 0 && count < 2,
    retryDelay: 2_000,
    onSuccess: (res, body) => {
      const messages: Record<string, string> = {
        accept: 'Mission acceptée',
        refuse: 'Mission refusée',
        release: 'Mission rendue : le responsable est prévenu',
        pick_up: 'Enlèvement confirmé',
        deliver: 'Livraison confirmée, merci !',
        fail: 'Incident déclaré',
      }
      toast.success(messages[body.action as string] ?? 'Enregistré')
      queryClient.setQueryData(['courier', 'mission', id], res.data)
      queryClient.invalidateQueries({ queryKey: ['courier', 'missions'] })
      setDialog(null)
    },
    onError: (e) => {
      if (e instanceof ApiError && e.status === 0) toast.error('Pas de réseau : action non enregistrée. Réessayez dès que la connexion revient.')
      else toast.error(errorMessage(e))
      // Conflit : la mission a changé (peut-être déjà enregistrée lors d'une coupure) → on affiche son état réel.
      if (e instanceof ApiError && (e.status === 409 || e.status === 0)) refetch()
    },
  })

  if (isLoading)
    return (
      <div className="space-y-3">
        <Skeleton className="h-10 w-40" />
        <Skeleton className="h-48" />
      </div>
    )
  if (isError || !mission)
    return (
      <div className="space-y-4">
        <BackLink />
        <ErrorState error={error} onRetry={() => refetch()} />
      </div>
    )

  const maps = mapsUrl(mission)
  const actions = mission.available_actions

  return (
    <div className="space-y-5 pb-28">
      <BackLink />
      <div className="flex items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{mission.order_reference}</h1>
          <p className="text-sm text-cocoa-500">{formatSlot(mission.slot_start_at, mission.slot_end_at)}</p>
        </div>
        <StatusPill status={mission.status} label={mission.status_label} />
      </div>

      {mission.requires_cold_chain && (
        <Notice tone="warning" title="Produits frais">
          <span className="inline-flex items-center gap-1">
            <Snowflake className="size-4" /> Gardez la commande au frais et livrez sans détour.
          </span>
          {mission.cold_chain_deadline_at && mission.status === 'picked_up' && (
            <strong className="mt-1 block">À remettre au client avant {formatTime(mission.cold_chain_deadline_at)}.</strong>
          )}
        </Notice>
      )}
      {mission.status === 'proposed' && <Notice>L'adresse complète et le téléphone du destinataire s'affichent dès que vous acceptez la mission.</Notice>}

      <Card className="space-y-3 p-4">
        <h2 className="font-semibold">Destinataire</h2>
        <p className="text-lg">{mission.recipient_first_name ?? '—'}</p>
        <p className="text-sm text-cocoa-700">{[mission.zone_name, mission.district, mission.street].filter(Boolean).join(' · ')}</p>
        {mission.landmark && <p className="text-sm text-cocoa-700">Repère : {mission.landmark}</p>}
        {mission.customer_notes && <p className="rounded bg-warning-50 px-3 py-2 text-sm text-warning-600">Consignes : {mission.customer_notes}</p>}
        {(mission.recipient_phone || maps) && (
          <div className="grid grid-cols-2 gap-2 pt-1 [&>*:last-child:nth-child(odd)]:col-span-2">
            {mission.recipient_phone && (
              <a href={`tel:${mission.recipient_phone}`} className={buttonClass('secondary', 'lg', 'w-full')}>
                <Phone className="size-5" /> Appeler
              </a>
            )}
            {mission.recipient_phone && (
              <a href={`https://wa.me/${mission.recipient_phone.replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className={buttonClass('secondary', 'lg', 'w-full')}>
                <MessageCircle className="size-5 text-[#1f7a4d]" /> WhatsApp
              </a>
            )}
            {maps && (
              <a href={maps} target="_blank" rel="noopener noreferrer" className={buttonClass('secondary', 'lg', 'w-full')}>
                <MapIcon className="size-5" /> Itinéraire
              </a>
            )}
          </div>
        )}
      </Card>

      <Card className="p-4">
        <h2 className="mb-2 font-semibold">Contenu</h2>
        <ul className="space-y-2 text-sm">
          {mission.items.map((i, idx) => (
            <li key={idx}>
              <span className="font-medium">
                {i.qty} × {i.name}
              </span>{' '}
              <span className="text-cocoa-500">– {i.variant}</span>
              {i.storage && <p className="text-xs text-cocoa-500">{i.storage}</p>}
            </li>
          ))}
        </ul>
        {mission.cash_to_collect_xof > 0 && <p className="mt-3 rounded bg-warning-50 px-3 py-2 text-sm font-semibold text-warning-600">À encaisser auprès du client : {formatXof(mission.cash_to_collect_xof)}</p>}
        <p className="mt-3 text-xs text-cocoa-500">Votre rémunération pour cette mission : {formatXof(mission.partner_fee_xof)}</p>
      </Card>

      <Card className="p-4 text-sm">
        <h2 className="mb-2 font-semibold">Suivi</h2>
        <ul className="space-y-1 text-cocoa-700">
          <li>Proposée : {formatDateTime(mission.assigned_at)}</li>
          {mission.accepted_at && <li>Acceptée : {formatDateTime(mission.accepted_at)}</li>}
          {mission.picked_up_at && <li>Enlevée : {formatDateTime(mission.picked_up_at)}</li>}
          {mission.delivered_at && (
            <li>
              Livrée : {formatDateTime(mission.delivered_at)} ({PROOF_LABELS[mission.proof_type ?? ''] ?? mission.proof_type})
            </li>
          )}
          {mission.failed_at && (
            <li>
              Échec : {formatDateTime(mission.failed_at)} ({INCIDENT_LABELS[mission.incident_reason ?? ''] ?? mission.incident_reason})
            </li>
          )}
        </ul>
      </Card>

      {actions.length > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-20 border-t border-cocoa-100 bg-cream-50/95 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] backdrop-blur">
          <div className="mx-auto flex max-w-xl gap-2">
            {actions.includes('accept') && (
              <>
                <Button variant="secondary" size="lg" className="flex-1" onClick={() => setDialog('refuse')}>
                  Refuser
                </Button>
                <Button size="lg" className="flex-[2]" loading={act.isPending} onClick={() => act.mutate({ action: 'accept' })}>
                  Accepter
                </Button>
              </>
            )}
            {actions.includes('release') && (
              <Button variant="secondary" size="lg" className="flex-1" onClick={() => setDialog('release')}>
                Rendre
              </Button>
            )}
            {actions.includes('pick_up') && (
              <Button size="lg" className="flex-[2]" onClick={() => setDialog('pick_up')}>
                Confirmer l'enlèvement
              </Button>
            )}
            {actions.includes('deliver') && (
              <>
                <Button variant="secondary" size="lg" className="flex-1" onClick={() => setDialog('fail')}>
                  Échec
                </Button>
                <Button variant="accent" size="lg" className="flex-[2]" onClick={() => setDialog('deliver')}>
                  Livrée
                </Button>
              </>
            )}
          </div>
        </div>
      )}

      {dialog === 'refuse' && <RefuseDialog loading={act.isPending} onClose={() => setDialog(null)} onSubmit={(reason) => act.mutate({ action: 'refuse', reason })} />}
      {dialog === 'release' && (
        <RefuseDialog
          title="Rendre la mission"
          label="Rendre la mission"
          placeholder="Ex. panne, accident, urgence…"
          help="La commande redevient disponible et le responsable des livraisons est prévenu pour la confier à un autre livreur."
          loading={act.isPending}
          onClose={() => setDialog(null)}
          onSubmit={(reason) => act.mutate({ action: 'release', reason })}
        />
      )}
      {dialog === 'pick_up' && <PickUpDialog cold={mission.requires_cold_chain} loading={act.isPending} onClose={() => setDialog(null)} onSubmit={() => act.mutate({ action: 'pick_up', packaging_checked: true })} />}
      {dialog === 'deliver' && (
        <DeliverDialog
          key={(overview.data?.proof_types ?? []).join(',')}
          proofTypes={overview.data?.proof_types ?? []}
          codeEnabled={overview.data?.delivery_code_enabled ?? false}
          attemptsLeft={mission.code_attempts_left}
          cashDue={mission.cash_to_collect_xof}
          loading={act.isPending}
          onClose={() => setDialog(null)}
          onSubmit={(body) => act.mutate({ action: 'deliver', ...body })}
        />
      )}
      {dialog === 'fail' && (
        <FailDialog
          reasons={overview.data?.incident_reasons ?? []}
          clientFaultReasons={overview.data?.client_fault_reasons ?? []}
          minWait={overview.data?.client_fault_min_wait_minutes ?? 10}
          feePercent={overview.data?.failed_fee_percent ?? 0}
          loading={act.isPending}
          onClose={() => setDialog(null)}
          onSubmit={(body) => act.mutate({ action: 'fail', ...body })}
        />
      )}
    </div>
  )
}

function BackLink() {
  return (
    <Link to="/livreur" className="inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-cocoa-500">
      <ArrowLeft className="size-4" /> Mes missions
    </Link>
  )
}

function DialogFooter({ onClose, onConfirm, loading, disabled, label }: { onClose: () => void; onConfirm: () => void; loading: boolean; disabled?: boolean; label: string }) {
  return (
    <div className="flex gap-2">
      <Button variant="secondary" size="lg" className="flex-1" onClick={onClose}>
        Retour
      </Button>
      <Button size="lg" className="flex-[2]" disabled={disabled} loading={loading} onClick={onConfirm}>
        {label}
      </Button>
    </div>
  )
}

function RefuseDialog({
  loading,
  onClose,
  onSubmit,
  title = 'Refuser la mission',
  label = 'Refuser',
  placeholder = 'Ex. panne, zone trop éloignée…',
  help,
}: {
  loading: boolean
  onClose: () => void
  onSubmit: (reason: string) => void
  title?: string
  label?: string
  placeholder?: string
  help?: string
}) {
  const [reason, setReason] = useState('')
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title={title} description={help} footer={<DialogFooter onClose={onClose} loading={loading} disabled={reason.trim().length < 3} label={label} onConfirm={() => onSubmit(reason.trim())} />}>
      <Field label="Motif" required>
        {(p) => <Textarea {...p} value={reason} onChange={(e) => setReason(e.target.value)} placeholder={placeholder} />}
      </Field>
    </Modal>
  )
}

function PickUpDialog({ cold, loading, onClose, onSubmit }: { cold: boolean; loading: boolean; onClose: () => void; onSubmit: () => void }) {
  const [checked, setChecked] = useState(false)
  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title="Enlèvement" footer={<DialogFooter onClose={onClose} loading={loading} disabled={!checked} label="Confirmer" onConfirm={onSubmit} />}>
      <Checkbox
        checked={checked}
        onChange={(e) => setChecked(e.target.checked)}
        label={cold ? "J'ai vérifié le conditionnement, le nombre de colis et le maintien au froid." : "J'ai vérifié le conditionnement et le nombre de colis."}
      />
    </Modal>
  )
}

function DeliverDialog({
  proofTypes,
  cashDue,
  codeEnabled,
  attemptsLeft,
  loading,
  onClose,
  onSubmit,
}: {
  proofTypes: string[]
  cashDue: number
  codeEnabled: boolean
  attemptsLeft: number
  loading: boolean
  onClose: () => void
  onSubmit: (body: Record<string, unknown>) => void
}) {
  const schema = z
    .object({
      proof_type: z.string().min(1, 'Choisissez un type de preuve'),
      proof_reference: z.string().trim().min(2, 'Précisez la preuve (nom de la personne, code…)'),
      cash: cashDue > 0 ? z.string().regex(/^\d+$/, 'Montant encaissé requis') : z.string().optional(),
    })
    .refine((v) => !codeEnabled || v.proof_type !== 'code' || /^\d{4}$/.test(v.proof_reference), { path: ['proof_reference'], message: 'Le code comporte 4 chiffres' })
  type Form = z.infer<typeof schema>
  const { register, handleSubmit, formState, control } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { proof_type: proofTypes[0] ?? '', proof_reference: '', cash: cashDue > 0 ? String(cashDue) : '' },
  })
  const proofType = useWatch({ control, name: 'proof_type' })
  const byCode = codeEnabled && proofType === 'code'
  const submit = handleSubmit((v) => onSubmit({ proof_type: v.proof_type, proof_reference: v.proof_reference, ...(cashDue > 0 ? { cash_collected_xof: Number(v.cash) } : {}) }))

  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title="Confirmer la remise" description="Une preuve de remise est obligatoire." footer={<DialogFooter onClose={onClose} loading={loading} label="Confirmer la livraison" onConfirm={submit} />}>
      <form className="grid gap-4" onSubmit={submit}>
        <Field label="Type de preuve" required error={formState.errors.proof_type?.message}>
          {(p) => (
            <Select {...p} {...register('proof_type')}>
              {proofTypes.map((t) => (
                <option key={t} value={t}>
                  {PROOF_LABELS[t] ?? t}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {byCode ? (
          <Field label="Code de remise donné par le client" required error={formState.errors.proof_reference?.message} hint={`4 chiffres, à demander au client. Essais restants : ${attemptsLeft}.`}>
            {(p) => <Input {...p} {...register('proof_reference')} inputMode="numeric" autoComplete="off" maxLength={4} className="text-center text-2xl tracking-[0.4em]" />}
          </Field>
        ) : (
          <>
            {codeEnabled && <Notice tone="warning">Remise sans code : le commercial vérifiera la livraison auprès du client.</Notice>}
            <Field label={codeEnabled ? 'Nom de la personne qui reçoit' : 'Détail de la preuve'} required error={formState.errors.proof_reference?.message} hint="Ex. « Remis à Awa en main propre ».">
              {(p) => <Input {...p} {...register('proof_reference')} />}
            </Field>
          </>
        )}
        {cashDue > 0 && (
          <Field label="Montant encaissé (FCFA)" required error={formState.errors.cash?.message} hint={`Montant attendu : ${formatXof(cashDue)}`}>
            {(p) => <Input {...p} inputMode="numeric" {...register('cash')} />}
          </Field>
        )}
      </form>
    </Modal>
  )
}

function FailDialog({
  reasons,
  clientFaultReasons,
  minWait,
  feePercent,
  loading,
  onClose,
  onSubmit,
}: {
  reasons: string[]
  clientFaultReasons: string[]
  minWait: number
  feePercent: number
  loading: boolean
  onClose: () => void
  onSubmit: (body: Record<string, unknown>) => void
}) {
  const schema = z.object({
    incident_reason: z.string().min(1, 'Choisissez un motif'),
    incident_note: z.string().max(1000).optional(),
    product_condition: z.enum(['intact', 'abime', 'rupture_froid', 'inconnu']),
    contact_attempted: z.boolean(),
  })
  type Form = z.infer<typeof schema>
  const { register, handleSubmit, formState, control } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: { incident_reason: '', incident_note: '', product_condition: 'intact', contact_attempted: false },
  })
  const reason = useWatch({ control, name: 'incident_reason' })
  const contacted = useWatch({ control, name: 'contact_attempted' })
  const clientFault = clientFaultReasons.includes(reason)
  const submit = handleSubmit((v) => onSubmit({ ...v, incident_note: v.incident_note || null }))

  return (
    <Modal open onOpenChange={(o) => !o && onClose()} title="Déclarer un échec de livraison" description="Le responsable des livraisons décidera de la suite." footer={<DialogFooter onClose={onClose} loading={loading} label="Déclarer l'échec" onConfirm={submit} />}>
      <form className="grid gap-4" onSubmit={submit}>
        <Field label="Motif" required error={formState.errors.incident_reason?.message}>
          {(p) => (
            <Select {...p} {...register('incident_reason')}>
              <option value="">Choisir…</option>
              {reasons.map((r) => (
                <option key={r} value={r}>
                  {INCIDENT_LABELS[r] ?? r}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="État du produit" required>
          {(p) => (
            <Select {...p} {...register('product_condition')}>
              {Object.entries(CONDITION_LABELS).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Checkbox label={`J'ai appelé ou écrit au client et attendu au moins ${minWait} minutes sur place`} {...register('contact_attempted')} />
        {clientFault && feePercent > 0 && (
          <Notice tone={contacted ? 'info' : 'warning'}>
            {contacted
              ? `Course rémunérée à ${feePercent} % (échec dû au client).`
              : `Sans tentative de contact ni attente de ${minWait} minutes, cette course ne sera pas rémunérée.`}
          </Notice>
        )}
        <Field label="Précisions" error={formState.errors.incident_note?.message}>{(p) => <Textarea {...p} {...register('incident_note')} />}</Field>
      </form>
    </Modal>
  )
}
