import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, UserPlus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, Notice, Skeleton } from '@/components/ui/Feedback'
import { Checkbox, Field, Input } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { api } from '@/lib/api'
import { formatDateTime } from '@/lib/format'
import { Toggle } from '../catalog/shared'
import { applyServerErrors } from '../catalog/utils'

interface StaffUser {
  id: string
  name: string
  email: string | null
  phone: string | null
  is_active: boolean
  last_login_at: string | null
  created_at: string
  roles: { id: number; name: string }[]
}

const STAFF_ROLES: { value: string; label: string; description: string }[] = [
  { value: 'admin', label: 'Administrateur', description: 'Tous les droits, y compris utilisateurs et paramètres.' },
  { value: 'commercial', label: 'Gestionnaire commercial', description: 'Commandes, clients, catalogue, paiements, promotions.' },
  { value: 'preparateur', label: 'Préparateur', description: 'File de préparation uniquement.' },
  { value: 'resp_livraison', label: 'Responsable livraisons', description: 'Livreurs, affectations, missions, règlements.' },
]
const ROLE_LABELS = Object.fromEntries(STAFF_ROLES.map((r) => [r.value, r.label]))

const strongPassword = z.string().min(12, '12 caractères minimum').regex(/[a-z]/, 'Une minuscule requise').regex(/[A-Z]/, 'Une majuscule requise').regex(/\d/, 'Un chiffre requis')

function makeSchema(isNew: boolean) {
  return z.object({
    name: z.string().trim().min(2, 'Nom requis').max(160),
    email: z.email('Email invalide'),
    phone: z.string().max(30),
    password: isNew ? strongPassword : z.union([z.literal(''), strongPassword]),
    is_active: z.boolean(),
    roles: z.array(z.string()).min(1, 'Choisissez au moins un rôle'),
  })
}
type FormValues = z.infer<ReturnType<typeof makeSchema>>

export function UsersPage() {
  const [editing, setEditing] = useState<StaffUser | 'new' | null>(null)
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['admin', 'users'],
    queryFn: () => api.get<{ data: StaffUser[]; meta: { roles: string[] } }>('/admin/users'),
  })

  return (
    <div>
      <PageHeader
        eyebrow="Administration"
        title="Utilisateurs"
        description="Comptes du personnel et rôles. Les droits sont contrôlés par le serveur sur chaque action."
        actions={
          <Button icon={<UserPlus className="size-4" />} onClick={() => setEditing('new')}>
            Nouvel utilisateur
          </Button>
        }
      />
      <Notice className="mb-5">
        Les livreurs partenaires sont gérés dans{' '}
        <Link to="/admin/livraisons/livreurs" className="font-medium underline">
          Livraisons › Livreurs
        </Link>
        . Les comptes clients se créent depuis la boutique et n'ont aucun accès au back-office.
      </Notice>
      {error ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : isLoading ? (
        <Skeleton className="h-60" />
      ) : !data?.data.length ? (
        <EmptyState title="Aucun utilisateur" />
      ) : (
        <Card className="divide-y divide-cocoa-100/60">
          {data.data.map((u) => (
            <div key={u.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <p className="font-semibold">{u.name}</p>
                <p className="truncate text-xs text-cocoa-500">
                  {u.email} · dernière connexion : {u.last_login_at ? formatDateTime(u.last_login_at) : 'jamais'}
                </p>
              </div>
              <div className="flex flex-wrap items-center gap-1.5">
                {u.roles.map((r) => (
                  <Badge key={r.id} tone={r.name === 'admin' ? 'raspberry' : 'neutral'}>
                    {ROLE_LABELS[r.name] ?? r.name}
                  </Badge>
                ))}
                <StatusPill status={u.is_active ? 'published' : 'inactive'} label={u.is_active ? 'Actif' : 'Désactivé'} />
                <Button size="sm" variant="ghost" aria-label={`Modifier ${u.name}`} onClick={() => setEditing(u)}>
                  <Pencil className="size-4" />
                </Button>
              </div>
            </div>
          ))}
        </Card>
      )}
      <UserModal user={editing} onClose={() => setEditing(null)} />
    </div>
  )
}

function UserModal({ user, onClose }: { user: StaffUser | 'new' | null; onClose: () => void }) {
  const queryClient = useQueryClient()
  const { me } = useAuth()
  const current = user && user !== 'new' ? user : null
  const { register, handleSubmit, reset, control, setError, formState } = useForm<FormValues>({ resolver: zodResolver(makeSchema(!current)) })

  useEffect(() => {
    if (!user) return
    reset({ name: current?.name ?? '', email: current?.email ?? '', phone: current?.phone ?? '', password: '', is_active: current?.is_active ?? true, roles: current?.roles.map((r) => r.name) ?? [] })
  }, [user, current, reset])

  const save = useMutation({
    mutationFn: (v: FormValues) => {
      const body = { ...v, phone: v.phone || null, password: v.password || null }
      return current ? api.put(`/admin/users/${current.id}`, body) : api.post('/admin/users', body)
    },
    onSuccess: () => {
      toast.success('Utilisateur enregistré')
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
      onClose()
    },
    onError: (e) => applyServerErrors(e, setError),
  })
  const e = formState.errors
  const isSelf = current?.id === me?.id

  return (
    <Modal
      open={!!user}
      onOpenChange={(o) => !o && onClose()}
      title={current ? `Modifier ${current.name}` : 'Nouvel utilisateur'}
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
      <form className="space-y-4" noValidate onSubmit={handleSubmit((v) => save.mutate(v))}>
        <Field label="Nom" required error={e.name?.message}>
          {(p) => <Input {...p} {...register('name')} autoComplete="off" />}
        </Field>
        <Field label="Email" required error={e.email?.message}>
          {(p) => <Input {...p} type="email" {...register('email')} autoComplete="off" />}
        </Field>
        <Field label="Téléphone" error={e.phone?.message}>
          {(p) => <Input {...p} type="tel" {...register('phone')} />}
        </Field>
        <Field label={current ? 'Nouveau mot de passe (laisser vide pour conserver)' : 'Mot de passe'} required={!current} error={e.password?.message} hint="12 caractères minimum, avec majuscule, minuscule et chiffre.">
          {(p) => <Input {...p} type="password" autoComplete="new-password" {...register('password')} />}
        </Field>
        <fieldset>
          <legend className="mb-1 text-sm font-medium">Rôles</legend>
          <Controller
            control={control}
            name="roles"
            render={({ field }) => (
              <div>
                {STAFF_ROLES.map((r) => (
                  <Checkbox
                    key={r.value}
                    label={
                      <span>
                        <span className="font-medium">{r.label}</span>
                        <span className="block text-xs text-cocoa-500">{r.description}</span>
                      </span>
                    }
                    checked={field.value?.includes(r.value) ?? false}
                    disabled={isSelf && r.value === 'admin'}
                    onChange={(ev) => field.onChange(ev.target.checked ? [...(field.value ?? []), r.value] : (field.value ?? []).filter((x) => x !== r.value))}
                  />
                ))}
                {e.roles?.message && <p className="text-sm text-error-600">{e.roles.message}</p>}
              </div>
            )}
          />
        </fieldset>
        <Controller
          control={control}
          name="is_active"
          render={({ field }) => <Toggle checked={!!field.value} onChange={field.onChange} disabled={isSelf} label="Compte actif" description="Un compte désactivé perd immédiatement l'accès." />}
        />
        {isSelf && <p className="text-xs text-cocoa-500">Vous ne pouvez pas retirer vos propres droits administrateur ni désactiver votre compte.</p>}
      </form>
    </Modal>
  )
}
