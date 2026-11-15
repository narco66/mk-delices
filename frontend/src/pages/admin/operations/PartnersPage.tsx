import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Users } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Skeleton } from '@/components/ui/Feedback'
import { Checkbox, Field, Input, Select, Textarea } from '@/components/ui/Form'
import { api, ApiError } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import type { Zone } from '@/types/api'
import { TRANSPORT_LABELS } from '../orders/shared'

interface Partner {
  id: string
  display_name: string
  phone: string
  transport_mode: string
  active: boolean
  available: boolean
  notes: string | null
  is_demo: boolean
  zones: { id: string; name: string }[]
  user: { id: string; email: string; is_active: boolean; last_login_at: string | null } | null
}

export function PartnersPage() {
  const [editing, setEditing] = useState<Partner | 'new' | null>(null)
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin', 'partners'],
    queryFn: async () => (await api.get<{ data: Partner[] }>('/admin/delivery/partners')).data,
  })

  return (
    <div>
      <PageHeader
        eyebrow="Logistique"
        title="Livreurs partenaires"
        description="Comptes livreurs, zones desservies et disponibilité."
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            Nouveau livreur
          </Button>
        }
      />
      {isLoading ? (
        <Skeleton className="h-64" />
      ) : isError || !data ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : data.length === 0 ? (
        <EmptyState icon={<Users className="size-6" />} title="Aucun livreur" description="Ajoutez un premier livreur partenaire." />
      ) : (
        <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {data.map((p) => (
            <li key={p.id}>
              <Card className="flex h-full flex-col p-5">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-semibold">{p.display_name}</p>
                    <p className="text-sm text-cocoa-500">{p.user?.email}</p>
                  </div>
                  <div className="flex flex-wrap justify-end gap-1">
                    <Badge tone={p.active ? 'success' : 'neutral'}>{p.active ? 'Actif' : 'Inactif'}</Badge>
                    {p.is_demo && <Badge tone="caramel">Démo</Badge>}
                  </div>
                </div>
                <dl className="mt-3 space-y-1 text-sm">
                  <div>
                    <dt className="inline text-cocoa-500">Téléphone : </dt>
                    <dd className="inline">{p.phone}</dd>
                  </div>
                  <div>
                    <dt className="inline text-cocoa-500">Transport : </dt>
                    <dd className="inline">{TRANSPORT_LABELS[p.transport_mode] ?? p.transport_mode}</dd>
                  </div>
                  <div>
                    <dt className="inline text-cocoa-500">Zones : </dt>
                    <dd className="inline">{p.zones.map((z) => z.name).join(', ') || '—'}</dd>
                  </div>
                  <div>
                    <dt className="inline text-cocoa-500">Dernière connexion : </dt>
                    <dd className="inline">{formatDateTime(p.user?.last_login_at)}</dd>
                  </div>
                </dl>
                <Button variant="secondary" size="sm" className="mt-4 self-start" onClick={() => setEditing(p)}>
                  Modifier
                </Button>
              </Card>
            </li>
          ))}
        </ul>
      )}
      {editing && <PartnerForm partner={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </div>
  )
}

function PartnerForm({ partner, onClose }: { partner: Partner | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const zones = useQuery({ queryKey: ['admin', 'zones'], queryFn: async () => (await api.get<{ data: Zone[] }>('/admin/delivery/zones')).data })

  const schema = z.object({
    display_name: z.string().trim().min(2, 'Nom requis'),
    email: z.string().trim().email('Email invalide'),
    phone: z.string().trim().min(9, 'Téléphone requis'),
    password: partner
      ? z.union([z.literal(''), z.string().min(10, '10 caractères minimum')])
      : z.string().min(10, '10 caractères minimum, lettres et chiffres'),
    transport_mode: z.enum(['moto', 'voiture', 'velo', 'a_pied']),
    active: z.boolean(),
    available: z.boolean(),
    notes: z.string().optional(),
    zone_ids: z.array(z.string()).min(1, 'Choisissez au moins une zone'),
  })
  type Form = z.infer<typeof schema>

  const { register, handleSubmit, formState, setError, watch, setValue } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: {
      display_name: partner?.display_name ?? '',
      email: partner?.user?.email ?? '',
      phone: partner?.phone ?? '',
      password: '',
      transport_mode: (partner?.transport_mode as Form['transport_mode']) ?? 'moto',
      active: partner?.active ?? true,
      available: partner?.available ?? true,
      notes: partner?.notes ?? '',
      zone_ids: partner?.zones.map((z) => z.id) ?? [],
    },
  })
  const zoneIds = watch('zone_ids')

  const save = useMutation({
    mutationFn: (v: Form) => {
      const body = { ...v, password: v.password || undefined, notes: v.notes || null }
      return partner ? api.put(`/admin/delivery/partners/${partner.id}`, body) : api.post('/admin/delivery/partners', body)
    },
    onSuccess: () => {
      toast.success(partner ? 'Livreur mis à jour' : 'Livreur créé')
      queryClient.invalidateQueries({ queryKey: ['admin', 'partners'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'delivery-board'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) Object.entries(e.fieldErrors).forEach(([k, m]) => setError(k.split('.')[0] as keyof Form, { message: m[0] }))
      toast.error(errorMessage(e))
    },
  })
  const err = formState.errors

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={partner ? 'Modifier le livreur' : 'Nouveau livreur'}
      description="Le compte livreur n'accède qu'à ses propres missions."
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
        <Field label="Nom affiché" required error={err.display_name?.message}>{(p) => <Input {...p} {...register('display_name')} />}</Field>
        <Field label="Téléphone" required error={err.phone?.message}>{(p) => <Input {...p} type="tel" {...register('phone')} />}</Field>
        <Field label="Email de connexion" required error={err.email?.message}>{(p) => <Input {...p} type="email" autoComplete="off" {...register('email')} />}</Field>
        <Field label={partner ? 'Nouveau mot de passe (facultatif)' : 'Mot de passe'} required={!partner} error={err.password?.message}>
          {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password')} />}
        </Field>
        <Field label="Transport" error={err.transport_mode?.message}>
          {(p) => (
            <Select {...p} {...register('transport_mode')}>
              {Object.entries(TRANSPORT_LABELS).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <div className="flex flex-col justify-end">
          <Checkbox label="Compte actif" {...register('active')} />
          <Checkbox label="Disponible" {...register('available')} />
        </div>
        <fieldset className="sm:col-span-2">
          <legend className="mb-1.5 text-sm font-medium text-cocoa-800">Zones desservies *</legend>
          {zones.isLoading ? (
            <Skeleton className="h-16" />
          ) : (
            <div className="flex flex-wrap gap-2">
              {(zones.data ?? []).map((z) => {
                const checked = zoneIds.includes(z.id)
                return (
                  <label key={z.id} className={`flex min-h-11 cursor-pointer items-center gap-2 rounded-full border px-3 text-sm ${checked ? 'border-cocoa-700 bg-cocoa-700 text-cream-50' : 'border-cocoa-100 bg-white'}`}>
                    <input
                      type="checkbox"
                      className="sr-only"
                      checked={checked}
                      onChange={() => setValue('zone_ids', checked ? zoneIds.filter((i) => i !== z.id) : [...zoneIds, z.id], { shouldValidate: true })}
                    />
                    {z.name}
                    {z.active === false && ' (inactive)'}
                  </label>
                )
              })}
            </div>
          )}
          {err.zone_ids && <p className="mt-1 text-sm font-medium text-error-600">{err.zone_ids.message}</p>}
        </fieldset>
        <Field label="Notes internes" className="sm:col-span-2">{(p) => <Textarea {...p} {...register('notes')} />}</Field>
      </form>
    </Modal>
  )
}
