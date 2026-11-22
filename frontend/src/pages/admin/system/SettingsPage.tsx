import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Badge, Card, PageHeader } from '@/components/ui/Display'
import { ErrorState, Notice, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Select, Textarea } from '@/components/ui/Form'
import { api } from '@/lib/api'
import { formatXof, PAYMENT_LABELS } from '@/lib/format'
import { TagInput, Toggle } from '../catalog/shared'
import { applyServerErrors } from '../catalog/utils'

interface SettingItem {
  key: string
  group: string
  value: unknown
  is_public: boolean
  requires_validation: boolean
  description: string | null
}

type Hours = Record<string, [string, string][]>

const COD = 'cash_on_delivery'
const COD_MAX_KEY = 'payments.cash_on_delivery_max_xof'

const GROUP_LABELS: Record<string, string> = {
  business: 'Entreprise et contact',
  ordering: 'Commandes et créneaux',
  payments: 'Paiements',
  policies: 'Politiques',
  delivery: 'Livraison',
  quality: 'Qualité et chaîne du froid',
  invoicing: 'Facturation et TVA',
  loyalty: 'Club MK (fidélité)',
}
const DAYS: [string, string][] = [
  ['mon', 'Lundi'],
  ['tue', 'Mardi'],
  ['wed', 'Mercredi'],
  ['thu', 'Jeudi'],
  ['fri', 'Vendredi'],
  ['sat', 'Samedi'],
  ['sun', 'Dimanche'],
]
const INTEGER_KEYS = [
  'ordering.slot_duration_minutes',
  'ordering.slot_capacity',
  'ordering.min_lead_minutes',
  'ordering.max_days_ahead',
  'ordering.cart_ttl_hours',
  'ordering.tracking_token_ttl_days',
  'ordering.max_qty_per_line',
  'quality.cold_chain_max_minutes',
  'delivery.failed_fee_percent',
  'invoicing.vat_rate_percent',
  'loyalty.orders_per_reward',
]
const LIST_KEYS = ['ordering.closed_dates', 'delivery.proof_types', 'delivery.incident_reasons', 'delivery.client_fault_reasons']
const LONG_TEXT_KEYS = ['policies.cancellation', 'invoicing.footer']
const SELECT_KEYS: Record<string, [string, string][]> = {
  'invoicing.issue_on': [
    ['delivered', 'À la livraison'],
    ['paid', 'Dès le paiement confirmé'],
  ],
}

export function SettingsPage() {
  const queryClient = useQueryClient()
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'settings'],
    queryFn: async () => (await api.get<{ data: SettingItem[] }>('/admin/settings')).data,
  })
  // Valeurs affichées = valeurs serveur + modifications locales non enregistrées.
  const [edits, setEdits] = useState<Record<string, unknown>>({})
  const values = useMemo(() => ({ ...Object.fromEntries((data ?? []).map((s) => [s.key, s.value])), ...edits }), [data, edits])
  const dirty = useMemo(() => new Set(Object.keys(edits)), [edits])

  const groups = useMemo(() => {
    const map = new Map<string, SettingItem[]>()
    data?.forEach((s) => map.set(s.group, [...(map.get(s.group) ?? []), s]))
    return [...map.entries()]
  }, [data])

  const save = useMutation({
    mutationFn: () => api.put('/admin/settings', { values: Object.fromEntries([...dirty].map((k) => [k, values[k]])) }),
    onSuccess: () => {
      toast.success('Paramètres enregistrés')
      setEdits({})
      queryClient.invalidateQueries({ queryKey: ['admin', 'settings'] })
      queryClient.invalidateQueries({ queryKey: ['config'] })
    },
    onError: (e) => applyServerErrors(e),
  })

  const set = (key: string, value: unknown) => {
    setEdits((e) => ({ ...e, [key]: value }))
  }

  if (error) return <ErrorState error={error} onRetry={() => refetch()} />

  return (
    <div className="pb-24">
      <PageHeader eyebrow="Administration" title="Paramètres" description="Paramètres métier éditables sans déploiement. Chaque modification est journalisée." />
      <Notice tone="warning" className="mb-6" title="Valeurs à valider">
        Les paramètres marqués « À valider par MK Délices » contiennent des valeurs techniques par défaut (horaires, capacités, délais, politiques) qui ne constituent pas
        des décisions commerciales définitives.
      </Notice>
      {isLoading ? (
        <Skeleton className="h-96" />
      ) : (
        <div className="space-y-6">
          {groups.map(([group, items]) => (
            <Card key={group} className="p-5">
              <h2 className="mb-4 text-lg font-semibold">{GROUP_LABELS[group] ?? group}</h2>
              {group === 'payments' && (
                <Notice className="mb-4">
                  Un moyen de paiement <strong>en ligne</strong> n'est proposé aux clients que si le <strong>paiement en ligne réel</strong> est activé, que le moyen est
                  activé ici <strong>et</strong> configuré techniquement (contrat marchand et identifiants serveur). Aucun contrat marchand n'est validé à ce jour : le paiement
                  en ligne réel reste coupé. Le{' '}
                  <strong>paiement à la livraison</strong> ne dépend d'aucun contrat : il se règle dans son propre bloc ci-dessous.
                </Notice>
              )}
              <div className="divide-y divide-cocoa-100/60">
                {items.map((s) =>
                  s.key === COD_MAX_KEY ? (
                    <div key={s.key} className="py-4 first:pt-0 last:pb-0">
                      <CashOnDeliveryEditor
                        enabled={!!(values['payments.methods'] as Record<string, boolean> | undefined)?.[COD]}
                        maxXof={(values[COD_MAX_KEY] as number | null | undefined) ?? null}
                        modified={dirty.has(COD_MAX_KEY) || dirty.has('payments.methods')}
                        onEnabledChange={(on) => set('payments.methods', { ...((values['payments.methods'] as Record<string, boolean>) ?? {}), [COD]: on })}
                        onMaxChange={(v) => set(COD_MAX_KEY, v)}
                      />
                    </div>
                  ) : (
                  <div key={s.key} className="py-4 first:pt-0 last:pb-0">
                    <div className="mb-2 flex flex-wrap items-center gap-2">
                      <p className="text-sm font-semibold">{s.description ?? s.key}</p>
                      {s.requires_validation && <Badge tone="warning">À valider par MK Délices</Badge>}
                      {s.is_public && <Badge>Public</Badge>}
                      {dirty.has(s.key) && <Badge tone="raspberry">Modifié</Badge>}
                    </div>
                    <p className="mb-2 font-mono text-[11px] text-cocoa-400">{s.key}</p>
                    <SettingEditor setting={s} value={values[s.key]} onChange={(v) => set(s.key, v)} />
                  </div>
                  ),
                )}
              </div>
            </Card>
          ))}
        </div>
      )}
      {dirty.size > 0 && (
        <div className="fixed inset-x-0 bottom-0 z-30 border-t border-cocoa-100 bg-white/95 backdrop-blur lg:left-[260px]">
          <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <p className="text-sm">{dirty.size} paramètre(s) modifié(s)</p>
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setEdits({})}>
                Annuler
              </Button>
              <Button loading={save.isPending} onClick={() => save.mutate()}>
                Enregistrer
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function SettingEditor({ setting, value, onChange }: { setting: SettingItem; value: unknown; onChange: (v: unknown) => void }) {
  const key = setting.key
  if (key === 'business.hours') return <HoursEditor value={(value as Hours) ?? {}} onChange={onChange} />
  if (key === 'payments.methods') {
    const methods = (value as Record<string, boolean>) ?? {}
    return (
      <div className="grid gap-1 sm:grid-cols-2">
        {/* Paiement à la livraison : réglé dans son bloc dédié (activation + plafond). */}
        {Object.keys(methods).filter((m) => m !== COD).map((m) => (
          <Toggle key={m} checked={!!methods[m]} onChange={(v) => onChange({ ...methods, [m]: v })} label={PAYMENT_LABELS[m] ?? m} />
        ))}
      </div>
    )
  }
  if (typeof setting.value === 'boolean' || typeof value === 'boolean') return <Toggle checked={!!value} onChange={onChange} label={value ? 'Activé' : 'Désactivé'} />
  if (INTEGER_KEYS.includes(key)) {
    return (
      <Input
        aria-label={setting.description ?? key}
        className="max-w-48"
        inputMode="numeric"
        value={value === null || value === undefined ? '' : String(value)}
        onChange={(e) => {
          const raw = e.target.value.replace(/\D/g, '')
          onChange(raw === '' ? null : Number(raw))
        }}
      />
    )
  }
  if (SELECT_KEYS[key]) {
    return (
      <Select aria-label={setting.description ?? key} className="max-w-xs" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)}>
        {SELECT_KEYS[key].map(([v, label]) => (
          <option key={v} value={v}>
            {label}
          </option>
        ))}
      </Select>
    )
  }
  if (LIST_KEYS.includes(key)) {
    return (
      <TagInput
        ariaLabel={setting.description ?? key}
        value={(value as string[]) ?? []}
        onChange={onChange}
        placeholder={key === 'ordering.closed_dates' ? 'AAAA-MM-JJ puis Entrée' : 'Ajouter puis Entrée'}
      />
    )
  }
  if (LONG_TEXT_KEYS.includes(key)) {
    return <Textarea aria-label={setting.description ?? key} rows={4} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || null)} />
  }
  return <Input aria-label={setting.description ?? key} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || null)} placeholder={key === 'business.whatsapp_number' ? '221XXXXXXXXX' : undefined} />
}

/**
 * Paiement à la livraison (D-PAY-04) : activation (payments.methods.cash_on_delivery) et plafond
 * (payments.cash_on_delivery_max_xof) réglés ensemble ; le plafond n'a de sens que si le paiement est activé.
 */
export function CashOnDeliveryEditor({
  enabled,
  maxXof,
  modified = false,
  onEnabledChange,
  onMaxChange,
}: {
  enabled: boolean
  maxXof: number | null
  modified?: boolean
  onEnabledChange: (enabled: boolean) => void
  onMaxChange: (max: number | null) => void
}) {
  return (
    <fieldset className="space-y-3">
      <legend className="mb-2 flex flex-wrap items-center gap-2 text-sm font-semibold">
        Paiement à la livraison
        <Badge tone="warning">À valider par MK Délices</Badge>
        {modified && <Badge tone="raspberry">Modifié</Badge>}
      </legend>
      <p className="text-sm text-cocoa-500">
        Le client règle en espèces au livreur, qui saisit le montant reçu ; le commercial le retrouve dans les règlements. Aucun contrat n'est nécessaire.
      </p>
      <Toggle checked={enabled} onChange={onEnabledChange} label={enabled ? 'Proposé aux clients' : 'Non proposé aux clients'} />
      <Field
        label="Montant maximal d'une commande payée à la livraison (FCFA)"
        hint={
          enabled
            ? 'Au-delà, la boutique exige un paiement en ligne. Laisser vide : sans plafond. Les commandes saisies par l’équipe ne sont pas limitées.'
            : 'Activez d’abord le paiement à la livraison.'
        }
      >
        {(p) => (
          <Input
            {...p}
            className="max-w-48"
            inputMode="numeric"
            disabled={!enabled}
            placeholder="Sans plafond"
            value={maxXof === null ? '' : String(maxXof)}
            onChange={(e) => {
              const raw = e.target.value.replace(/\D/g, '')
              onMaxChange(raw === '' ? null : Number(raw))
            }}
          />
        )}
      </Field>
      {enabled && maxXof !== null && <p className="text-sm">Soit {formatXof(maxXof)} maximum par commande payée à la livraison.</p>}
    </fieldset>
  )
}

/** Horaires hebdomadaires : plages [ouverture, fermeture] par jour (heure de Dakar). */
function HoursEditor({ value, onChange }: { value: Hours; onChange: (v: Hours) => void }) {
  const setDay = (day: string, ranges: [string, string][]) => onChange({ ...value, [day]: ranges })
  return (
    <div className="space-y-2">
      {DAYS.map(([day, label]) => {
        const ranges = value[day] ?? []
        return (
          <div key={day} className="flex flex-col gap-2 rounded-[var(--radius-control)] bg-cream-100 p-3 sm:flex-row sm:items-center">
            <span className="w-24 shrink-0 text-sm font-medium">{label}</span>
            <div className="flex flex-1 flex-wrap items-center gap-2">
              {ranges.length === 0 && <span className="text-sm text-cocoa-500">Fermé</span>}
              {ranges.map(([open, close], i) => (
                <span key={i} className="inline-flex items-center gap-1">
                  <Field label={`${label} ouverture ${i + 1}`} className="[&>label]:sr-only">
                    {(p) => <Input {...p} type="time" className="w-28" value={open} onChange={(e) => setDay(day, ranges.map((r, j) => (j === i ? [e.target.value, r[1]] : r)))} />}
                  </Field>
                  <span aria-hidden>–</span>
                  <Field label={`${label} fermeture ${i + 1}`} className="[&>label]:sr-only">
                    {(p) => <Input {...p} type="time" className="w-28" value={close} onChange={(e) => setDay(day, ranges.map((r, j) => (j === i ? [r[0], e.target.value] : r)))} />}
                  </Field>
                  <button type="button" className="grid size-9 place-items-center rounded-full hover:bg-cream-200" aria-label={`Supprimer la plage ${i + 1} du ${label}`} onClick={() => setDay(day, ranges.filter((_, j) => j !== i))}>
                    <Trash2 className="size-4" />
                  </button>
                </span>
              ))}
              <Button type="button" size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => setDay(day, [...ranges, ['09:00', '18:00']])}>
                Plage
              </Button>
            </div>
          </div>
        )
      })}
    </div>
  )
}
