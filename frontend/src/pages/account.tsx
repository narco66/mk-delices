import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LogOut, MapPin, Package, ShieldOff, Trash2, UserRound } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { Logo } from '@/components/brand/Brand'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Card, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Checkbox, Field, Input, Select } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { useZones } from '@/hooks/usePublicData'
import { useSeo } from '@/hooks/useSeo'
import { api, ApiError } from '@/lib/api'
import { cn } from '@/lib/cn'
import { formatDateTime, formatSlot, formatXof } from '@/lib/format'
import { InvoiceList } from '@/features/invoices/InvoiceList'
import type { Me, Paginated, PublicOrder } from '@/types/api'

function homeFor(me: Me): string {
  if (me.is_staff) return '/admin'
  if (me.is_courier) return '/livreur'
  return '/compte'
}

function safeRedirect(target: string | null): string | null {
  return target && target.startsWith('/') && !target.startsWith('//') ? target : null
}

const loginSchema = z.object({
  email: z.string().trim().email('Adresse email invalide.'),
  password: z.string().min(1, 'Mot de passe requis.'),
  remember: z.boolean(),
})

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [serverError, setServerError] = useState<string | null>(null)
  const form = useForm<z.infer<typeof loginSchema>>({ resolver: zodResolver(loginSchema), defaultValues: { email: '', password: '', remember: false } })
  useSeo({ title: 'Connexion', noindex: true })

  const onSubmit = form.handleSubmit(async (v) => {
    setServerError(null)
    try {
      const me = await login(v.email, v.password, v.remember)
      navigate(safeRedirect(params.get('redirect')) ?? homeFor(me), { replace: true })
    } catch (e) {
      setServerError(e instanceof ApiError && e.status === 429 ? 'Trop de tentatives. Patientez une minute avant de réessayer.' : errorMessage(e))
    }
  })

  return (
    <div className="container-page grid min-h-[70vh] place-items-center py-12">
      <Card className="w-full max-w-md p-6 sm:p-8">
        <Logo />
        <h1 className="mt-6 text-3xl font-semibold">Connexion</h1>
        <p className="mt-1 text-sm text-cocoa-500">Clients, équipe MK Délices et livreurs partenaires.</p>
        <form onSubmit={onSubmit} noValidate className="mt-6 space-y-4">
          {serverError && <Notice tone="error">{serverError}</Notice>}
          <Field label="Email" required error={form.formState.errors.email?.message}>
            {(p) => <Input {...p} {...form.register('email')} type="email" autoComplete="email" />}
          </Field>
          <Field label="Mot de passe" required error={form.formState.errors.password?.message}>
            {(p) => <Input {...p} {...form.register('password')} type="password" autoComplete="current-password" />}
          </Field>
          <Checkbox {...form.register('remember')} label="Rester connecté sur cet appareil" />
          <Button type="submit" size="lg" className="w-full" loading={form.formState.isSubmitting}>
            Se connecter
          </Button>
        </form>
        <p className="mt-6 text-center text-sm text-cocoa-500">
          Pas encore de compte ?{' '}
          <Link to="/inscription" className="font-medium text-raspberry-600 underline">
            Créer un compte client
          </Link>
        </p>
        <p className="mt-2 text-center text-xs text-cocoa-500">Le compte est facultatif : vous pouvez commander en invité.</p>
      </Card>
    </div>
  )
}

const registerSchema = z
  .object({
    first_name: z.string().trim().min(2, 'Prénom requis.').max(80),
    last_name: z.string().trim().max(80).optional(),
    email: z.string().trim().email('Adresse email invalide.'),
    phone: z.string().trim().min(9, 'Téléphone requis.'),
    password: z.string().min(10, '10 caractères minimum.').regex(/[A-Za-z]/, 'Au moins une lettre.').regex(/\d/, 'Au moins un chiffre.'),
    password_confirmation: z.string(),
    accepted_privacy: z.boolean().refine((v) => v, 'Veuillez accepter la politique de confidentialité.'),
    marketing_consent: z.boolean(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Les mots de passe ne correspondent pas.' })

type RegisterForm = z.infer<typeof registerSchema>

export function RegisterPage() {
  const { register: signUp } = useAuth()
  const navigate = useNavigate()
  const form = useForm<RegisterForm>({
    resolver: zodResolver(registerSchema),
    defaultValues: { first_name: '', last_name: '', email: '', phone: '', password: '', password_confirmation: '', accepted_privacy: false, marketing_consent: false },
  })
  const { errors } = form.formState
  useSeo({ title: 'Créer un compte', noindex: true })

  const onSubmit = form.handleSubmit(async (v) => {
    try {
      await signUp(v)
      toast.success('Bienvenue chez MK Délices !')
      navigate('/compte', { replace: true })
    } catch (e) {
      if (e instanceof ApiError) {
        Object.entries(e.fieldErrors).forEach(([k, m]) => form.setError(k as keyof RegisterForm, { message: m[0] }))
        if (e.code === 'invalid_phone') form.setError('phone', { message: e.message })
      }
      toast.error(errorMessage(e))
    }
  })

  return (
    <div className="container-page grid place-items-center py-12">
      <Card className="w-full max-w-lg p-6 sm:p-8">
        <h1 className="text-3xl font-semibold">Créer un compte</h1>
        <p className="mt-1 text-sm text-cocoa-500">Retrouvez vos commandes et vos adresses en un clin d'œil.</p>
        <form onSubmit={onSubmit} noValidate className="mt-6 grid gap-4 sm:grid-cols-2">
          <Field label="Prénom" required error={errors.first_name?.message}>
            {(p) => <Input {...p} {...form.register('first_name')} autoComplete="given-name" />}
          </Field>
          <Field label="Nom" error={errors.last_name?.message}>
            {(p) => <Input {...p} {...form.register('last_name')} autoComplete="family-name" />}
          </Field>
          <Field label="Email" required error={errors.email?.message} className="sm:col-span-2">
            {(p) => <Input {...p} {...form.register('email')} type="email" autoComplete="email" />}
          </Field>
          <Field label="Téléphone" required error={errors.phone?.message} className="sm:col-span-2">
            {(p) => <Input {...p} {...form.register('phone')} type="tel" autoComplete="tel" placeholder="77 123 45 67" />}
          </Field>
          <Field label="Mot de passe" required error={errors.password?.message} hint="10 caractères, lettres et chiffres.">
            {(p) => <Input {...p} {...form.register('password')} type="password" autoComplete="new-password" />}
          </Field>
          <Field label="Confirmation" required error={errors.password_confirmation?.message}>
            {(p) => <Input {...p} {...form.register('password_confirmation')} type="password" autoComplete="new-password" />}
          </Field>
          <div className="sm:col-span-2">
            <Checkbox
              {...form.register('accepted_privacy')}
              error={errors.accepted_privacy?.message}
              label={
                <>
                  J'ai lu la{' '}
                  <Link to="/confidentialite" target="_blank" className="underline">
                    politique de confidentialité
                  </Link>
                  .
                </>
              }
            />
            <Checkbox {...form.register('marketing_consent')} label="Je souhaite recevoir les offres de MK Délices (facultatif, révocable)." />
          </div>
          <Button type="submit" size="lg" className="sm:col-span-2" loading={form.formState.isSubmitting}>
            Créer mon compte
          </Button>
        </form>
      </Card>
    </div>
  )
}

export function ForbiddenPage() {
  const { me } = useAuth()
  useSeo({ title: 'Accès refusé', noindex: true })
  return (
    <div className="container-page py-16">
      <EmptyState
        icon={<ShieldOff className="size-6" />}
        title="Accès refusé"
        description="Votre compte ne dispose pas des droits nécessaires pour cette page."
        action={<ButtonLink to={me ? homeFor(me) : '/'}>Retour</ButtonLink>}
      />
    </div>
  )
}

interface Address {
  id: string
  zone_id: string
  zone?: { id: string; name: string }
  label: string | null
  district: string
  street: string | null
  landmark: string | null
}

export function AccountPage() {
  const { me, logout } = useAuth()
  const navigate = useNavigate()
  const [tab, setTab] = useState<'orders' | 'profile' | 'addresses'>('orders')
  useSeo({ title: 'Mon espace', noindex: true })

  return (
    <div className="container-page py-10">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm text-cocoa-500">Bonjour,</p>
          <h1 className="text-4xl font-semibold">{me?.customer?.first_name ?? me?.name}</h1>
        </div>
        <Button
          variant="ghost"
          icon={<LogOut className="size-4" />}
          onClick={async () => {
            await logout()
            navigate('/')
          }}
        >
          Se déconnecter
        </Button>
      </div>
      {/* Onglets accessibles : flèches gauche/droite, onglet relié à son panneau (audit lot 3). */}
      <div
        className="mt-8 flex gap-2 overflow-x-auto"
        role="tablist"
        aria-label="Mon compte"
        onKeyDown={(e) => {
          if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
          const keys = ['orders', 'profile', 'addresses'] as const
          const next = keys[(keys.indexOf(tab) + (e.key === 'ArrowRight' ? 1 : keys.length - 1)) % keys.length]
          setTab(next)
          document.getElementById(`tab-${next}`)?.focus()
        }}
      >
        {(
          [
            ['orders', 'Mes commandes', <Package key="o" className="size-4" />],
            ['profile', 'Profil & préférences', <UserRound key="p" className="size-4" />],
            ['addresses', 'Adresses', <MapPin key="a" className="size-4" />],
          ] as const
        ).map(([key, label, icon]) => (
          <button key={key} id={`tab-${key}`} role="tab" aria-selected={tab === key} aria-controls="panneau-compte" tabIndex={tab === key ? 0 : -1} onClick={() => setTab(key)} className={cn('flex min-h-11 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-medium', tab === key ? 'bg-cocoa-700 text-cream-50' : 'bg-white hover:bg-cream-200')}>
            {icon}
            {label}
          </button>
        ))}
      </div>
      <div className="mt-6" id="panneau-compte" role="tabpanel" aria-labelledby={`tab-${tab}`}>
        {tab === 'orders' && <AccountOrders />}
        {tab === 'profile' && <AccountProfile />}
        {tab === 'addresses' && <AccountAddresses />}
      </div>
    </div>
  )
}

function AccountOrders() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['account', 'orders'], queryFn: () => api.get<Paginated<PublicOrder>>('/account/orders') })
  if (isLoading) return <Skeleton className="h-40" />
  if (error) return <ErrorState error={error} onRetry={() => refetch()} />
  if (!data?.data.length) return <EmptyState icon={<Package className="size-6" />} title="Aucune commande pour le moment" action={<ButtonLink to="/boutique">Découvrir la boutique</ButtonLink>} />
  return (
    <ul className="space-y-3">
      {data.data.map((o) => (
        <li key={o.reference}>
          <Link to={`/suivi/${o.reference}`} className="block rounded-[var(--radius-card)] bg-white p-5 shadow-soft transition hover:shadow-lift">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="font-semibold">{o.reference}</p>
              <div className="flex gap-2">
                <StatusPill status={o.status} label={o.status_label} />
                <StatusPill status={o.payment_status} label={o.payment_status_label} />
              </div>
            </div>
            <p className="mt-1 text-sm text-cocoa-500">
              {formatDateTime(o.created_at)} · {o.items.reduce((n, i) => n + i.qty, 0)} article(s) · {formatXof(o.total_xof)}
            </p>
            <p className="text-sm text-cocoa-500 capitalize">{formatSlot(o.slot_start_at, o.slot_end_at)}</p>
          </Link>
          {(o.invoices ?? []).length > 0 && (
            <div className="mt-2 px-1">
              <InvoiceList invoices={o.invoices} pathFor={(i) => `/orders/${o.reference}/invoices/${i.number}`} />
            </div>
          )}
        </li>
      ))}
    </ul>
  )
}

const profileSchema = z.object({
  first_name: z.string().trim().min(2).max(80),
  last_name: z.string().trim().max(80).optional(),
  phone: z.string().trim().min(9, 'Téléphone requis.'),
  marketing_consent: z.boolean(),
})

function AccountProfile() {
  const { me } = useAuth()
  const queryClient = useQueryClient()
  const form = useForm<z.infer<typeof profileSchema>>({
    resolver: zodResolver(profileSchema),
    defaultValues: { first_name: me?.customer?.first_name ?? '', last_name: me?.customer?.last_name ?? '', phone: me?.customer?.phone ?? '', marketing_consent: me?.customer?.marketing_consent ?? false },
  })
  const save = form.handleSubmit(async (v) => {
    try {
      await api.put('/account/profile', v)
      await queryClient.invalidateQueries({ queryKey: ['me'] })
      toast.success('Profil mis à jour')
    } catch (e) {
      toast.error(errorMessage(e))
    }
  })
  return (
    <Card className="max-w-xl p-6">
      <form onSubmit={save} noValidate className="grid gap-4 sm:grid-cols-2">
        <Field label="Prénom" required error={form.formState.errors.first_name?.message}>
          {(p) => <Input {...p} {...form.register('first_name')} />}
        </Field>
        <Field label="Nom">{(p) => <Input {...p} {...form.register('last_name')} />}</Field>
        <Field label="Téléphone" required error={form.formState.errors.phone?.message} className="sm:col-span-2">
          {(p) => <Input {...p} {...form.register('phone')} type="tel" />}
        </Field>
        <p className="text-sm text-cocoa-500 sm:col-span-2">Email : {me?.email}</p>
        <Checkbox className="sm:col-span-2" {...form.register('marketing_consent')} label="Recevoir les offres et nouveautés de MK Délices (révocable à tout moment)." />
        <Button type="submit" className="sm:col-span-2 sm:justify-self-start" loading={form.formState.isSubmitting}>
          Enregistrer
        </Button>
      </form>
    </Card>
  )
}

function AccountAddresses() {
  const queryClient = useQueryClient()
  const { data: zones } = useZones()
  const { data, isLoading } = useQuery({ queryKey: ['account', 'addresses'], queryFn: async () => (await api.get<{ data: Address[] }>('/account/addresses')).data })
  const [draft, setDraft] = useState({ zone_id: '', district: '', landmark: '', label: '' })
  const add = useMutation({
    mutationFn: () => api.post('/account/addresses', draft),
    onSuccess: () => {
      setDraft({ zone_id: '', district: '', landmark: '', label: '' })
      queryClient.invalidateQueries({ queryKey: ['account', 'addresses'] })
      toast.success('Adresse ajoutée')
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const remove = useMutation({
    mutationFn: (id: string) => api.delete(`/account/addresses/${id}`),
    onSuccess: () => {
      toast.success('Adresse supprimée')
      queryClient.invalidateQueries({ queryKey: ['account', 'addresses'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <div className="space-y-3">
        {isLoading && <Skeleton className="h-24" />}
        {data?.length === 0 && <EmptyState title="Aucune adresse enregistrée" />}
        {data?.map((a) => (
          <Card key={a.id} className="flex items-start justify-between gap-3 p-5">
            <div>
              <p className="font-semibold">{a.label || a.district}</p>
              <p className="text-sm text-cocoa-500">
                {a.district} · {a.zone?.name}
              </p>
              {a.landmark && <p className="text-sm text-cocoa-500">{a.landmark}</p>}
            </div>
            <button type="button" onClick={() => window.confirm(`Supprimer l'adresse « ${a.label ?? a.district} » ?`) && remove.mutate(a.id)} disabled={remove.isPending} className="grid size-10 place-items-center rounded-full text-cocoa-400 hover:bg-error-50 hover:text-error-600" aria-label="Supprimer l'adresse">
              <Trash2 className="size-4" />
            </button>
          </Card>
        ))}
      </div>
      <Card className="p-6">
        <h2 className="mb-4 font-semibold">Nouvelle adresse</h2>
        <div className="grid gap-4">
          <Field label="Nom (ex. Maison, Bureau)">{(p) => <Input {...p} value={draft.label} onChange={(e) => setDraft({ ...draft, label: e.target.value })} />}</Field>
          <Field label="Zone" required>
            {(p) => (
              <Select {...p} value={draft.zone_id} onChange={(e) => setDraft({ ...draft, zone_id: e.target.value })}>
                <option value="">Choisir…</option>
                {zones?.map((z) => (
                  <option key={z.id} value={z.id}>
                    {z.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label="Quartier" required>{(p) => <Input {...p} value={draft.district} onChange={(e) => setDraft({ ...draft, district: e.target.value })} />}</Field>
          <Field label="Point de repère">{(p) => <Input {...p} value={draft.landmark} onChange={(e) => setDraft({ ...draft, landmark: e.target.value })} />}</Field>
          <Button onClick={() => add.mutate()} loading={add.isPending} disabled={!draft.zone_id || draft.district.length < 2}>
            Ajouter
          </Button>
        </div>
      </Card>
    </div>
  )
}
