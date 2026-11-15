import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Tag } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, Notice, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Select, Textarea } from '@/components/ui/Form'
import { api } from '@/lib/api'
import { formatDateTime, formatXof } from '@/lib/format'
import { Toggle } from './shared'
import { applyServerErrors, toNullableInt } from './utils'

interface Promotion {
  id: string
  code: string
  name: string
  description: string | null
  discount_type: 'percent' | 'fixed'
  discount_value: number
  min_subtotal_xof: number | null
  max_discount_xof: number | null
  starts_at: string | null
  ends_at: string | null
  usage_limit: number | null
  used_count: number
  active: boolean
  is_public: boolean
  is_demo: boolean
}

const int = z.string().regex(/^\d*$/, 'Nombre entier positif')
const schema = z
  .object({
    code: z.string().trim().min(2, 'Code requis').max(40).regex(/^[A-Za-z0-9\-_]+$/, 'Lettres, chiffres, - et _'),
    name: z.string().trim().min(2, 'Nom requis').max(120),
    description: z.string().max(500),
    discount_type: z.enum(['percent', 'fixed']),
    discount_value: z.string().regex(/^\d+$/, 'Valeur requise'),
    min_subtotal_xof: int,
    max_discount_xof: int,
    starts_at: z.string(),
    ends_at: z.string(),
    usage_limit: int,
    active: z.boolean(),
    is_public: z.boolean(),
  })
  .refine((v) => v.discount_type !== 'percent' || Number(v.discount_value) <= 100, { path: ['discount_value'], message: '100 % maximum' })
  .refine((v) => !v.starts_at || !v.ends_at || v.ends_at > v.starts_at, { path: ['ends_at'], message: 'La fin doit suivre le début' })
type FormValues = z.infer<typeof schema>

/** Valeur datetime-local (heure de Dakar = UTC+0). */
const toLocal = (iso: string | null) => (iso ? iso.slice(0, 16) : '')

function promoState(p: Promotion): { status: string; label: string } {
  const now = new Date().toISOString()
  if (!p.active) return { status: 'inactive', label: 'Inactive' }
  if (p.ends_at && p.ends_at < now) return { status: 'expired', label: 'Expirée' }
  if (p.starts_at && p.starts_at > now) return { status: 'pending', label: 'Programmée' }
  if (p.usage_limit !== null && p.used_count >= p.usage_limit) return { status: 'expired', label: 'Épuisée' }
  return { status: 'published', label: 'Active' }
}

export function PromotionsAdminPage() {
  const [editing, setEditing] = useState<Promotion | 'new' | null>(null)
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'promotions'],
    queryFn: async () => (await api.get<{ data: Promotion[] }>('/admin/promotions')).data,
  })

  return (
    <div>
      <PageHeader
        eyebrow="Commerce"
        title="Promotions"
        description="Codes promotionnels appliqués au panier et recalculés par le serveur."
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            Nouveau code
          </Button>
        }
      />
      <Notice className="mb-5">Un seul code par commande : les remises ne se cumulent pas. La remise est plafonnée au sous-total (et au plafond éventuel).</Notice>
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : isLoading ? (
        <Skeleton className="h-40" />
      ) : !data?.length ? (
        <EmptyState icon={<Tag />} title="Aucun code promotionnel" action={<Button onClick={() => setEditing('new')}>Créer un code</Button>} />
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {data.map((p) => {
            const st = promoState(p)
            return (
              <li key={p.id}>
                <Card className="p-4">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="font-mono text-lg font-semibold">{p.code}</p>
                      <p className="text-sm">{p.name}</p>
                    </div>
                    <Button size="sm" variant="ghost" aria-label={`Modifier ${p.code}`} onClick={() => setEditing(p)}>
                      <Pencil className="size-4" />
                    </Button>
                  </div>
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    <StatusPill status={st.status} label={st.label} />
                    <Badge tone="raspberry">{p.discount_type === 'percent' ? `−${p.discount_value} %` : `−${formatXof(p.discount_value)}`}</Badge>
                    {p.is_public && <Badge>Publique</Badge>}
                    {p.is_demo && <Badge tone="warning">Démo</Badge>}
                  </div>
                  <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-cocoa-500">
                    <dt>Utilisations</dt>
                    <dd className="text-right tabular-nums text-cocoa-800">
                      {p.used_count}
                      {p.usage_limit !== null ? ` / ${p.usage_limit}` : ''}
                    </dd>
                    <dt>Minimum</dt>
                    <dd className="text-right text-cocoa-800">{formatXof(p.min_subtotal_xof)}</dd>
                    <dt>Plafond</dt>
                    <dd className="text-right text-cocoa-800">{formatXof(p.max_discount_xof)}</dd>
                    <dt>Validité</dt>
                    <dd className="text-right text-cocoa-800">
                      {p.starts_at ? formatDateTime(p.starts_at) : 'Immédiate'} → {p.ends_at ? formatDateTime(p.ends_at) : 'sans fin'}
                    </dd>
                  </dl>
                </Card>
              </li>
            )
          })}
        </ul>
      )}
      <PromotionModal promotion={editing} onClose={() => setEditing(null)} />
    </div>
  )
}

function PromotionModal({ promotion, onClose }: { promotion: Promotion | 'new' | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const current = promotion && promotion !== 'new' ? promotion : null
  const { register, handleSubmit, reset, control, setError, formState } = useForm<FormValues>({ resolver: zodResolver(schema) })
  const discountType = useWatch({ control, name: 'discount_type' })

  useEffect(() => {
    if (!promotion) return
    reset({
      code: current?.code ?? '',
      name: current?.name ?? '',
      description: current?.description ?? '',
      discount_type: current?.discount_type ?? 'percent',
      discount_value: current ? String(current.discount_value) : '',
      min_subtotal_xof: current?.min_subtotal_xof?.toString() ?? '',
      max_discount_xof: current?.max_discount_xof?.toString() ?? '',
      starts_at: toLocal(current?.starts_at ?? null),
      ends_at: toLocal(current?.ends_at ?? null),
      usage_limit: current?.usage_limit?.toString() ?? '',
      active: current?.active ?? true,
      is_public: current?.is_public ?? false,
    })
  }, [promotion, current, reset])

  const save = useMutation({
    mutationFn: (v: FormValues) => {
      const body = {
        ...v,
        code: v.code.toUpperCase(),
        description: v.description || null,
        discount_value: Number(v.discount_value),
        min_subtotal_xof: toNullableInt(v.min_subtotal_xof),
        max_discount_xof: toNullableInt(v.max_discount_xof),
        usage_limit: toNullableInt(v.usage_limit),
        starts_at: v.starts_at ? `${v.starts_at}:00Z` : null,
        ends_at: v.ends_at ? `${v.ends_at}:00Z` : null,
      }
      return current ? api.put(`/admin/promotions/${current.id}`, body) : api.post('/admin/promotions', body)
    },
    onSuccess: () => {
      toast.success('Code promotionnel enregistré')
      queryClient.invalidateQueries({ queryKey: ['admin', 'promotions'] })
      onClose()
    },
    onError: (e) => applyServerErrors(e, setError),
  })
  const e = formState.errors

  return (
    <Modal
      open={!!promotion}
      onOpenChange={(o) => !o && onClose()}
      title={current ? `Modifier ${current.code}` : 'Nouveau code promotionnel'}
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
      <form className="grid gap-4 sm:grid-cols-2" noValidate onSubmit={handleSubmit((v) => save.mutate(v))}>
        <Field label="Code" required error={e.code?.message}>
          {(p) => <Input {...p} className="uppercase" {...register('code')} />}
        </Field>
        <Field label="Nom" required error={e.name?.message}>
          {(p) => <Input {...p} {...register('name')} />}
        </Field>
        <Field label="Type de remise" required>
          {(p) => (
            <Select {...p} {...register('discount_type')}>
              <option value="percent">Pourcentage</option>
              <option value="fixed">Montant fixe (FCFA)</option>
            </Select>
          )}
        </Field>
        <Field label={discountType === 'percent' ? 'Valeur (%)' : 'Valeur (FCFA)'} required error={e.discount_value?.message}>
          {(p) => <Input {...p} inputMode="numeric" {...register('discount_value')} />}
        </Field>
        <Field label="Sous-total minimum (FCFA)" error={e.min_subtotal_xof?.message}>
          {(p) => <Input {...p} inputMode="numeric" {...register('min_subtotal_xof')} />}
        </Field>
        <Field label="Remise maximale (FCFA)" error={e.max_discount_xof?.message}>
          {(p) => <Input {...p} inputMode="numeric" {...register('max_discount_xof')} />}
        </Field>
        <Field label="Début (heure de Dakar)" error={e.starts_at?.message}>
          {(p) => <Input {...p} type="datetime-local" {...register('starts_at')} />}
        </Field>
        <Field label="Fin (heure de Dakar)" error={e.ends_at?.message}>
          {(p) => <Input {...p} type="datetime-local" {...register('ends_at')} />}
        </Field>
        <Field label="Nombre d'utilisations max." error={e.usage_limit?.message} hint={current ? `Déjà utilisé ${current.used_count} fois` : undefined}>
          {(p) => <Input {...p} inputMode="numeric" {...register('usage_limit')} />}
        </Field>
        <Field label="Description" error={e.description?.message} className="sm:col-span-2">
          {(p) => <Textarea {...p} rows={2} {...register('description')} />}
        </Field>
        <div className="sm:col-span-2">
          <Controller control={control} name="active" render={({ field }) => <Toggle checked={!!field.value} onChange={field.onChange} label="Active" />} />
          <Controller control={control} name="is_public" render={({ field }) => <Toggle checked={!!field.value} onChange={field.onChange} label="Afficher sur la page Promotions" />} />
        </div>
      </form>
    </Modal>
  )
}
