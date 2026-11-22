import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Heart, LogOut, MapPin, Package, Pencil, RotateCcw, ShieldOff, Star, Trash2, UserRound, UserX } from 'lucide-react'
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
import { useFavorites } from '@/features/account/useFavorites'
import { useAuth } from '@/features/auth/AuthContext'
import { useCart } from '@/features/cart/CartContext'
import { ProductCard } from '@/features/catalog/components'
import { LocationPicker } from '@/features/maps/LocationPicker'
import { useZones } from '@/hooks/usePublicData'
import { useSeo } from '@/hooks/useSeo'
import { api, ApiError } from '@/lib/api'
import { safeRedirect } from '@/lib/redirect'
import { orderStorage } from '@/lib/storage'
import { cn } from '@/lib/cn'
import { formatDateTime, formatSlot, formatXof } from '@/lib/format'
import { InvoiceList } from '@/features/invoices/InvoiceList'
import type { CartState, LoyaltyCard, Me, Paginated, PickedLocation, PublicOrder } from '@/types/api'

function homeFor(me: Me): string {
  if (me.is_staff) return '/admin'
  if (me.is_courier) return '/livreur'
  return '/compte'
}

const loginSchema = z.object({
  identifier: z.string().trim().min(3, 'Saisissez votre email ou votre numéro de téléphone.'),
  password: z.string().min(1, 'Mot de passe requis.'),
  remember: z.boolean(),
})

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [serverError, setServerError] = useState<string | null>(null)
  const form = useForm<z.infer<typeof loginSchema>>({ resolver: zodResolver(loginSchema), defaultValues: { identifier: '', password: '', remember: false } })
  useSeo({ title: 'Connexion', noindex: true })

  const onSubmit = form.handleSubmit(async (v) => {
    setServerError(null)
    try {
      const me = await login(v.identifier, v.password, v.remember)
      navigate(safeRedirect(params.get('redirect')) ?? homeFor(me), { replace: true })
    } catch (e) {
      setServerError(e instanceof ApiError && e.status === 429 ? 'Trop de tentatives. Patientez avant de réessayer, ou utilisez « Mot de passe oublié ».' : errorMessage(e))
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
          <Field label="Email ou téléphone" required error={form.formState.errors.identifier?.message}>
            {(p) => <Input {...p} {...form.register('identifier')} autoComplete="username" placeholder="vous@exemple.com ou 77 123 45 67" />}
          </Field>
          <Field label="Mot de passe" required error={form.formState.errors.password?.message}>
            {(p) => <Input {...p} {...form.register('password')} type="password" autoComplete="current-password" />}
          </Field>
          <p className="-mt-2 text-right text-sm">
            <Link to="/mot-de-passe-oublie" className="text-raspberry-600 underline">
              Mot de passe oublié ?
            </Link>
          </p>
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
        if (e.code === 'invalid_phone' || e.code === 'phone_taken') form.setError('phone', { message: e.message })
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

export function ForgotPasswordPage() {
  const [sent, setSent] = useState<string | null>(null)
  const form = useForm<{ email: string }>({ resolver: zodResolver(z.object({ email: z.string().trim().email('Adresse email invalide.') })), defaultValues: { email: '' } })
  useSeo({ title: 'Mot de passe oublié', noindex: true })
  const onSubmit = form.handleSubmit(async (v) => {
    try {
      setSent((await api.post<{ data: { message: string } }>('/auth/forgot-password', v)).data.message)
    } catch (e) {
      toast.error(e instanceof ApiError && e.status === 429 ? 'Trop de demandes. Patientez quelques minutes avant de réessayer.' : errorMessage(e))
    }
  })
  return (
    <div className="container-page grid min-h-[70vh] place-items-center py-12">
      <Card className="w-full max-w-md p-6 sm:p-8">
        <h1 className="text-3xl font-semibold">Mot de passe oublié</h1>
        {sent ? (
          <div className="mt-6 space-y-4">
            <Notice tone="success">{sent}</Notice>
            <p className="text-sm text-cocoa-500">Le lien est valable une heure. Pas d'email ? Contactez le service client, nous vous aiderons à récupérer votre compte.</p>
            <ButtonLink to="/connexion" variant="secondary" className="w-full">
              Retour à la connexion
            </ButtonLink>
          </div>
        ) : (
          <form onSubmit={onSubmit} noValidate className="mt-6 space-y-4">
            <p className="text-sm text-cocoa-500">Saisissez l'adresse email de votre compte : nous vous envoyons un lien pour choisir un nouveau mot de passe.</p>
            <Field label="Email" required error={form.formState.errors.email?.message}>
              {(p) => <Input {...p} {...form.register('email')} type="email" autoComplete="email" />}
            </Field>
            <Button type="submit" size="lg" className="w-full" loading={form.formState.isSubmitting}>
              Recevoir le lien
            </Button>
            <p className="text-center text-sm">
              <Link to="/connexion" className="text-raspberry-600 underline">
                Retour à la connexion
              </Link>
            </p>
          </form>
        )}
      </Card>
    </div>
  )
}

const resetSchema = z
  .object({
    password: z.string().min(10, '10 caractères minimum.').regex(/[A-Za-z]/, 'Au moins une lettre.').regex(/\d/, 'Au moins un chiffre.'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Les mots de passe ne correspondent pas.' })

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const form = useForm<z.infer<typeof resetSchema>>({ resolver: zodResolver(resetSchema), defaultValues: { password: '', password_confirmation: '' } })
  useSeo({ title: 'Nouveau mot de passe', noindex: true })
  const onSubmit = form.handleSubmit(async (v) => {
    try {
      const res = await api.post<{ data: { message: string } }>('/auth/reset-password', { ...v, token, email })
      toast.success(res.data.message)
      navigate('/connexion', { replace: true })
    } catch (e) {
      if (e instanceof ApiError) Object.entries(e.fieldErrors).forEach(([k, m]) => k in v && form.setError(k as keyof typeof v, { message: m[0] }))
      toast.error(errorMessage(e))
    }
  })
  return (
    <div className="container-page grid min-h-[70vh] place-items-center py-12">
      <Card className="w-full max-w-md p-6 sm:p-8">
        <h1 className="text-3xl font-semibold">Nouveau mot de passe</h1>
        {!token || !email ? (
          <div className="mt-6 space-y-4">
            <Notice tone="error">Ce lien est incomplet. Ouvrez le lien reçu par email, ou faites une nouvelle demande.</Notice>
            <ButtonLink to="/mot-de-passe-oublie" className="w-full">
              Nouvelle demande
            </ButtonLink>
          </div>
        ) : (
          <form onSubmit={onSubmit} noValidate className="mt-6 space-y-4">
            <p className="text-sm text-cocoa-500">Compte : {email}. Tous vos appareils connectés seront déconnectés.</p>
            <Field label="Nouveau mot de passe" required error={form.formState.errors.password?.message} hint="10 caractères, lettres et chiffres.">
              {(p) => <Input {...p} {...form.register('password')} type="password" autoComplete="new-password" />}
            </Field>
            <Field label="Confirmation" required error={form.formState.errors.password_confirmation?.message}>
              {(p) => <Input {...p} {...form.register('password_confirmation')} type="password" autoComplete="new-password" />}
            </Field>
            <Button type="submit" size="lg" className="w-full" loading={form.formState.isSubmitting}>
              Enregistrer le mot de passe
            </Button>
          </form>
        )}
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
  lat: number | null
  lng: number | null
  location_source: string | null
  location_accuracy_m: number | null
  is_default: boolean
}

const EMPTY_DRAFT = { zone_id: '', district: '', street: '', landmark: '', label: '' }

export function AccountPage() {
  const { me, logout } = useAuth()
  const navigate = useNavigate()
  const [tab, setTab] = useState<'orders' | 'favorites' | 'profile' | 'addresses'>('orders')
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
            const tracked = orderStorage.all().length
            await logout()
            navigate('/')
            // Audit W-06 : appareil partagé, les liens de suivi mémorisés peuvent être oubliés en un geste.
            if (tracked > 0) {
              toast('Vous êtes déconnecté.', {
                description: `${tracked} commande(s) restent consultables sur cet appareil.`,
                action: { label: 'Les oublier', onClick: () => orderStorage.clear() },
                duration: 10_000,
              })
            }
          }}
        >
          Se déconnecter
        </Button>
      </div>
      <LoyaltyCardPanel />
      {/* Onglets accessibles : flèches gauche/droite, onglet relié à son panneau (audit lot 3). */}
      <div
        className="mt-8 flex gap-2 overflow-x-auto"
        role="tablist"
        aria-label="Mon compte"
        onKeyDown={(e) => {
          if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return
          const keys = ['orders', 'favorites', 'profile', 'addresses'] as const
          const next = keys[(keys.indexOf(tab) + (e.key === 'ArrowRight' ? 1 : keys.length - 1)) % keys.length]
          setTab(next)
          document.getElementById(`tab-${next}`)?.focus()
        }}
      >
        {(
          [
            ['orders', 'Mes commandes', <Package key="o" className="size-4" />],
            ['favorites', 'Mes favoris', <Heart key="f" className="size-4" />],
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
        {tab === 'favorites' && <AccountFavorites />}
        {tab === 'profile' && (
          <>
            <AccountProfile />
            <DeleteAccount />
          </>
        )}
        {tab === 'addresses' && <AccountAddresses />}
      </div>
    </div>
  )
}

/** Club MK (maquette mobile) : carte à tampons, une récompense toutes les N commandes livrées. */
function LoyaltyCardPanel() {
  const { data } = useQuery({ queryKey: ['account', 'loyalty'], queryFn: async () => (await api.get<{ data: LoyaltyCard }>('/account/loyalty')).data })
  if (!data?.enabled) return null
  return (
    <section aria-labelledby="club-mk" className="mt-6 overflow-hidden rounded-[var(--radius-card)] bg-cocoa-800 p-6 text-cream-50 shadow-soft">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p className="text-xs font-semibold tracking-[0.2em] text-caramel-300 uppercase" id="club-mk">
            Club MK
          </p>
          <p className="mt-1 text-2xl font-semibold">
            {data.delivered_orders} délice{data.delivered_orders > 1 ? 's' : ''} savouré{data.delivered_orders > 1 ? 's' : ''}
          </p>
          <p className="mt-1 text-sm text-cream-200">
            Encore {data.remaining} commande{data.remaining > 1 ? 's' : ''} avant {data.reward_label}.
            {data.rewards_earned > 0 && ` Vous avez déjà gagné ${data.rewards_earned} récompense${data.rewards_earned > 1 ? 's' : ''}.`}
          </p>
        </div>
        <p className="font-display text-4xl font-semibold" aria-label={`${data.stamps} tampon(s) sur ${data.orders_per_reward}`}>
          {String(data.stamps).padStart(2, '0')}
          <span className="text-lg text-cream-300">/{String(data.orders_per_reward).padStart(2, '0')}</span>
        </p>
      </div>
      <ol className="mt-4 flex gap-1.5" aria-hidden>
        {Array.from({ length: data.orders_per_reward }, (_, i) => (
          <li key={i} className={cn('h-2 flex-1 rounded-full', i < data.stamps ? 'bg-caramel-400' : 'bg-cream-50/20')} />
        ))}
      </ol>
      <p className="mt-3 text-xs text-cream-300">Chaque commande livrée compte. La récompense vous est remise par l'équipe MK Délices.</p>
    </section>
  )
}

function AccountFavorites() {
  const { data, isLoading, error, refetch } = useFavorites()
  if (isLoading) return <Skeleton className="h-40" />
  if (error) return <ErrorState error={error} onRetry={() => refetch()} />
  if (!data?.length)
    return (
      <EmptyState
        icon={<Heart className="size-6" />}
        title="Aucun favori pour le moment"
        description="Touchez le cœur sur un produit pour le retrouver ici."
        action={<ButtonLink to="/boutique">Découvrir la boutique</ButtonLink>}
      />
    )
  return (
    <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
      {data.map((p) => (
        <ProductCard key={p.id} product={p} />
      ))}
    </div>
  )
}

/** « Recommander » : remet les articles dans le panier, aux prix du jour. */
function ReorderButton({ reference }: { reference: string }) {
  const { headers, adopt, setDrawerOpen } = useCart()
  const reorder = useMutation({
    mutationFn: async () => (await api.post<{ data: CartState }>(`/account/orders/${reference}/reorder`, {}, { headers: headers() })).data,
    onSuccess: (cart) => {
      adopt(cart)
      const skipped = cart.skipped ?? []
      if (skipped.length) toast.warning(`Non ajouté (indisponible) : ${skipped.map((s) => s.name).join(', ')}`)
      toast.success('Articles ajoutés au panier, aux prix du jour', { action: { label: 'Voir le panier', onClick: () => setDrawerOpen(true) } })
      setDrawerOpen(true)
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Button size="sm" variant="secondary" icon={<RotateCcw className="size-4" />} loading={reorder.isPending} onClick={() => reorder.mutate()}>
      Recommander
    </Button>
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
          <div className="mt-2 flex flex-wrap items-start justify-between gap-2 px-1">
            {(o.invoices ?? []).length > 0 ? <InvoiceList invoices={o.invoices} pathFor={(i) => `/orders/${o.reference}/invoices/${i.number}`} /> : <span />}
            <ReorderButton reference={o.reference} />
          </div>
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

/**
 * Suppression du compte (exigence Google Play / App Store) : identité, coordonnées, adresses, favoris et
 * appareils effacés ; commandes et factures conservées sans lien avec la personne (obligations comptables).
 */
function DeleteAccount() {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')
  const remove = useMutation({
    mutationFn: () => api.delete('/account', { body: { password } }),
    onSuccess: async () => {
      // Accueil d'abord : sinon la page compte, protégée, redirige vers la connexion à la fin de session.
      navigate('/', { replace: true })
      await logout({ forgetDevice: true })
      toast.success('Votre compte a été supprimé. Merci d’avoir fait confiance à MK Délices.')
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Card className="mt-6 max-w-xl border border-error-50 p-6">
      <h2 className="flex items-center gap-2 font-semibold text-error-600">
        <UserX className="size-5" aria-hidden /> Supprimer mon compte
      </h2>
      <p className="mt-2 text-sm text-cocoa-500">
        Vos informations personnelles, adresses, favoris et appareils connectés sont effacés définitivement. Les commandes et factures déjà émises sont conservées sans lien
        avec votre identité, comme la loi l'exige. Une commande en cours doit être livrée ou annulée avant la suppression.
      </p>
      {open ? (
        <form
          className="mt-4 space-y-3"
          onSubmit={(e) => {
            e.preventDefault()
            remove.mutate()
          }}
        >
          <Field label="Confirmez avec votre mot de passe" required>
            {(p) => <Input {...p} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />}
          </Field>
          <div className="flex flex-wrap gap-2">
            <Button type="submit" variant="danger" loading={remove.isPending} disabled={!password}>
              Supprimer définitivement
            </Button>
            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
              Annuler
            </Button>
          </div>
        </form>
      ) : (
        <Button className="mt-4" variant="secondary" onClick={() => setOpen(true)}>
          Supprimer mon compte…
        </Button>
      )}
    </Card>
  )
}

function AccountAddresses() {
  const queryClient = useQueryClient()
  const { data: zones } = useZones()
  const { data, isLoading } = useQuery({ queryKey: ['account', 'addresses'], queryFn: async () => (await api.get<{ data: Address[] }>('/account/addresses')).data })
  const [draft, setDraft] = useState(EMPTY_DRAFT)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [location, setLocation] = useState<PickedLocation | null>(null)
  const [zoneFromPosition, setZoneFromPosition] = useState(false)
  // Le formulaire est remonté à chaque changement d'adresse éditée (état interne du sélecteur de position).
  const [formKey, setFormKey] = useState(0)
  const reset = () => {
    setDraft(EMPTY_DRAFT)
    setEditingId(null)
    setLocation(null)
    setZoneFromPosition(false)
    setFormKey((k) => k + 1)
  }
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['account', 'addresses'] })
  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...draft,
        street: draft.street || null,
        landmark: draft.landmark || null,
        label: draft.label || null,
        // « saved » = position déjà enregistrée et non modifiée : son origine n'est pas connue du formulaire.
        ...(location ? { lat: location.lat, lng: location.lng, location_source: location.source === 'saved' ? null : location.source, accuracy_m: location.accuracy_m ?? null } : {}),
      }
      return editingId ? api.put(`/account/addresses/${editingId}`, body) : api.post('/account/addresses', body)
    },
    onSuccess: () => {
      toast.success(editingId ? 'Adresse modifiée' : 'Adresse ajoutée')
      reset()
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const remove = useMutation({
    mutationFn: (id: string) => api.delete(`/account/addresses/${id}`),
    onSuccess: (_r, id) => {
      toast.success('Adresse supprimée')
      if (id === editingId) reset()
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const makeDefault = useMutation({
    mutationFn: (id: string) => api.post(`/account/addresses/${id}/default`),
    onSuccess: () => {
      toast.success('Adresse par défaut enregistrée')
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const edit = (a: Address) => {
    setEditingId(a.id)
    setDraft({ zone_id: a.zone_id, district: a.district, street: a.street ?? '', landmark: a.landmark ?? '', label: a.label ?? '' })
    const source = a.location_source === 'gps' || a.location_source === 'map' || a.location_source === 'search' ? a.location_source : 'saved'
    setLocation(a.lat !== null && a.lng !== null ? { lat: a.lat, lng: a.lng, source, accuracy_m: a.location_accuracy_m } : null)
    setZoneFromPosition(false)
    setFormKey((k) => k + 1)
  }

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <div className="space-y-3">
        {isLoading && <Skeleton className="h-24" />}
        {data?.length === 0 && <EmptyState title="Aucune adresse enregistrée" />}
        {data?.map((a) => (
          <Card key={a.id} className={cn('flex items-start justify-between gap-3 p-5', editingId === a.id && 'ring-2 ring-caramel-400')}>
            <div>
              <p className="flex flex-wrap items-center gap-2 font-semibold">
                {a.label || a.district}
                {a.is_default && <span className="rounded-full bg-cream-100 px-2 py-0.5 text-xs font-medium text-cocoa-700">Par défaut</span>}
              </p>
              <p className="text-sm text-cocoa-500">
                {[a.street, a.district].filter(Boolean).join(', ')} · {a.zone?.name}
              </p>
              {a.landmark && <p className="text-sm text-cocoa-500">{a.landmark}</p>}
              {a.lat !== null ? (
                <p className="mt-1 flex items-center gap-1 text-xs text-success-600">
                  <MapPin className="size-3" aria-hidden /> Position enregistrée
                </p>
              ) : (
                <p className="mt-1 text-xs text-cocoa-500">Sans position (le livreur se fie au repère)</p>
              )}
              {!a.is_default && (
                <button type="button" className="mt-2 flex items-center gap-1 text-sm text-raspberry-600 hover:underline" onClick={() => makeDefault.mutate(a.id)} disabled={makeDefault.isPending}>
                  <Star className="size-3.5" aria-hidden /> Définir par défaut
                </button>
              )}
            </div>
            <div className="flex">
              <button type="button" onClick={() => edit(a)} className="grid size-10 place-items-center rounded-full text-cocoa-400 hover:bg-cream-100 hover:text-cocoa-700" aria-label={`Modifier l'adresse « ${a.label ?? a.district} »`}>
                <Pencil className="size-4" />
              </button>
              <button type="button" onClick={() => window.confirm(`Supprimer l'adresse « ${a.label ?? a.district} » ?`) && remove.mutate(a.id)} disabled={remove.isPending} className="grid size-10 place-items-center rounded-full text-cocoa-400 hover:bg-error-50 hover:text-error-600" aria-label={`Supprimer l'adresse « ${a.label ?? a.district} »`}>
                <Trash2 className="size-4" />
              </button>
            </div>
          </Card>
        ))}
      </div>
      <Card className="p-6" key={formKey}>
        <h2 className="mb-4 font-semibold">{editingId ? "Modifier l'adresse" : 'Nouvelle adresse'}</h2>
        <div className="grid gap-4">
          <Field label="Nom (ex. Maison, Bureau)">{(p) => <Input {...p} value={draft.label} onChange={(e) => setDraft({ ...draft, label: e.target.value })} />}</Field>
          <LocationPicker
            value={location}
            zoneId={draft.zone_id}
            zones={zones}
            onChange={(loc, res) => {
              setLocation(loc)
              const fromPosition = !!loc && !!res?.zone && res.zone_source === 'position'
              setZoneFromPosition(fromPosition)
              if (fromPosition) setDraft((d) => ({ ...d, zone_id: res!.zone!.id }))
            }}
            onSuggestion={(place) => setDraft((d) => ({ ...d, district: d.district || place.district || '', street: d.street || place.street || '' }))}
          />
          <Field label="Zone" required hint={zoneFromPosition ? 'Déterminée par la position.' : location ? 'Vérifiée avec la position à l’enregistrement.' : undefined}>
            {(p) => (
              <Select {...p} value={draft.zone_id} disabled={zoneFromPosition} onChange={(e) => setDraft({ ...draft, zone_id: e.target.value })}>
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
          <Field label="Rue / villa">{(p) => <Input {...p} value={draft.street} onChange={(e) => setDraft({ ...draft, street: e.target.value })} />}</Field>
          <Field label="Point de repère">{(p) => <Input {...p} value={draft.landmark} onChange={(e) => setDraft({ ...draft, landmark: e.target.value })} />}</Field>
          <div className="flex flex-wrap gap-2">
            <Button onClick={() => save.mutate()} loading={save.isPending} disabled={!draft.zone_id || draft.district.length < 2}>
              {editingId ? 'Enregistrer' : 'Ajouter'}
            </Button>
            {editingId && (
              <Button variant="ghost" onClick={reset}>
                Annuler
              </Button>
            )}
          </div>
        </div>
      </Card>
    </div>
  )
}
