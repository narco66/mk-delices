import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { AlertCircle, CalendarClock, CreditCard, Lock, MessageCircle, ShoppingBag, Tag, Trash2, Truck } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { Link, useNavigate } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { DessertArt } from '@/components/brand/Brand'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Badge, Card, Price, QuantityStepper } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Skeleton, Spinner } from '@/components/ui/Feedback'
import { Checkbox, Field, Input, Select, Textarea } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { LocationPicker } from '@/features/maps/LocationPicker'
import { useCart } from '@/features/cart/CartContext'
import { usePublicConfig, useZones } from '@/hooks/usePublicData'
import { useSeo } from '@/hooks/useSeo'
import { api, ApiError, newIdempotencyKey } from '@/lib/api'
import { cn } from '@/lib/cn'
import { allergenLabel, formatXof, PAYMENT_LABELS } from '@/lib/format'
import { orderStorage } from '@/lib/storage'
import type { GeoResolution, PickedLocation, PublicOrder, Quote, Slot } from '@/types/api'

/** Adresse enregistrée du client connecté (carnet d'adresses). */
interface SavedAddress {
  id: string
  zone_id: string
  label: string | null
  district: string
  street: string | null
  landmark: string | null
  lat: number | null
  lng: number | null
  location_accuracy_m: number | null
  is_default: boolean
}

function Summary({ quote, compact = false }: { quote: Quote; compact?: boolean }) {
  return (
    <dl className="space-y-2 text-sm">
      {!compact &&
        quote.lines.map((l) => (
          <div key={l.item_id} className="flex justify-between gap-3">
            <dt className="text-cocoa-700">
              {l.quantity} × {l.product_name} <span className="text-cocoa-500">({l.variant_label})</span>
            </dt>
            <dd className="shrink-0 tabular-nums">{formatXof(l.line_total_xof)}</dd>
          </div>
        ))}
      <div className={cn('flex justify-between', !compact && 'border-t border-cocoa-100/70 pt-2')}>
        <dt>Sous-total</dt>
        <dd className="tabular-nums">{formatXof(quote.subtotal_xof)}</dd>
      </div>
      {quote.discount_xof > 0 && (
        <div className="flex justify-between text-success-600">
          <dt>Remise {quote.promotion ? `(${quote.promotion.code})` : ''}</dt>
          <dd className="tabular-nums">−{formatXof(quote.discount_xof)}</dd>
        </div>
      )}
      <div className="flex justify-between">
        <dt>Livraison {quote.zone ? `· ${quote.zone.name}` : ''}</dt>
        <dd className="tabular-nums">{quote.zone || quote.fulfillment_type === 'pickup' ? formatXof(quote.delivery_fee_xof) : 'Selon la zone'}</dd>
      </div>
      <div className="flex justify-between border-t border-cocoa-100/70 pt-3 text-lg font-semibold">
        <dt>Total</dt>
        <dd className="tabular-nums">{formatXof(quote.total_xof)}</dd>
      </div>
    </dl>
  )
}

/** UX-04 — Panier. */
export function CartPage() {
  const { cart, isLoading, update, remove, applyPromotion, isMutating, count } = useCart()
  const [code, setCode] = useState('')
  useSeo({ title: 'Votre panier', noindex: true })
  const lines = count > 0 ? (cart?.lines ?? []) : []
  const safe = (fn: () => Promise<void>) => fn().catch((e) => toast.error(errorMessage(e)))

  if (isLoading) return <Spinner />
  if (!lines.length) {
    return (
      <div className="container-page py-16">
        <EmptyState icon={<ShoppingBag className="size-6" />} title="Votre panier est vide" description="Nos tiramisus n'attendent que vous." action={<ButtonLink to="/nos-tiramisus">Découvrir nos tiramisus</ButtonLink>} />
      </div>
    )
  }

  return (
    <div className="container-page py-10">
      <h1 className="text-4xl font-semibold">Votre panier</h1>
      <div className="mt-8 grid gap-8 lg:grid-cols-[1fr_380px]">
        <div className="space-y-3">
          {cart!.issues.map((i) => (
            <Notice key={i.code + i.message} tone="warning">
              {i.message}
            </Notice>
          ))}
          <ul className="divide-y divide-cocoa-100/70 rounded-[var(--radius-card)] bg-white shadow-soft">
            {lines.map((line) => (
              <li key={line.item_id} className="flex gap-4 p-4">
                <DessertArt seed={line.product_slug} className="size-24 shrink-0 rounded-[var(--radius-control)]" label={false} />
                <div className="min-w-0 flex-1">
                  <div className="flex justify-between gap-2">
                    <Link to={`/produits/${line.product_slug}`} className="font-semibold hover:underline">
                      {line.product_name}
                    </Link>
                    <Price amount={line.line_total_xof} />
                  </div>
                  <p className="text-sm text-cocoa-500">
                    {line.variant_label} · {formatXof(line.unit_price_xof)} l'unité
                  </p>
                  {line.allergens.length > 0 && <p className="mt-1 text-xs text-cocoa-500">Allergènes : {line.allergens.map(allergenLabel).join(', ')}</p>}
                  {!line.available && <p className="text-sm font-medium text-error-600">Plus disponible : retirez cet article pour continuer.</p>}
                  <div className="mt-3 flex items-center justify-between">
                    <QuantityStepper size="sm" label={`Quantité ${line.product_name}`} value={line.quantity} disabled={isMutating} onChange={(q) => safe(() => update(line.item_id, q))} />
                    <button type="button" onClick={() => safe(() => remove(line.item_id))} className="flex min-h-10 items-center gap-1 rounded-full px-3 text-sm text-cocoa-500 hover:bg-error-50 hover:text-error-600">
                      <Trash2 className="size-4" aria-hidden /> Retirer
                    </button>
                  </div>
                </div>
              </li>
            ))}
          </ul>
        </div>
        <aside className="space-y-4 lg:sticky lg:top-24 lg:self-start">
          <Card className="p-5">
            <form
              className="mb-4 flex gap-2"
              onSubmit={(e) => {
                e.preventDefault()
                safe(() => applyPromotion(code.trim() || null))
              }}
            >
              <label className="flex-1">
                <span className="sr-only">Code promotionnel</span>
                <Input value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} placeholder={cart!.promotion_code ?? 'Code promo'} maxLength={40} />
              </label>
              <Button type="submit" variant="secondary" loading={isMutating} icon={<Tag className="size-4" />}>
                Appliquer
              </Button>
            </form>
            {cart!.promotion_code && (
              <p className="mb-4 flex items-center justify-between text-sm">
                <span>
                  Code <Badge tone="cocoa">{cart!.promotion_code}</Badge>
                </span>
                <button type="button" className="text-cocoa-500 underline" onClick={() => safe(() => applyPromotion(null))}>
                  Retirer
                </button>
              </p>
            )}
            <Summary quote={cart!} compact />
            <ButtonLink to="/commander" size="lg" className="mt-5 w-full">
              Passer commande
            </ButtonLink>
            <p className="mt-3 flex items-center justify-center gap-1.5 text-xs text-cocoa-500">
              <Lock className="size-3.5" aria-hidden /> Montants calculés par notre serveur
            </p>
          </Card>
        </aside>
      </div>
    </div>
  )
}

const phoneRegex = /^(?:\+?221|00221)?\s*(?:7[05678]|3[03])(?:[\s.-]?\d){7}$/

const checkoutSchema = z
  .object({
    first_name: z.string().trim().min(2, 'Indiquez le prénom du destinataire.').max(80),
    last_name: z.string().trim().max(80).optional(),
    phone: z.string().trim().regex(phoneRegex, 'Numéro sénégalais attendu (ex. 77 123 45 67).'),
    email: z.union([z.literal(''), z.string().trim().email('Adresse email invalide.')]).optional(),
    fulfillment_type: z.enum(['delivery', 'pickup']),
    zone_id: z.string().optional(),
    district: z.string().trim().max(120).optional(),
    street: z.string().trim().max(200).optional(),
    landmark: z.string().trim().max(255).optional(),
    slot_start_at: z.string().min(1, 'Choisissez un créneau.'),
    payment_method: z.string().optional(),
    notes: z.string().max(500).optional(),
    accepted_terms: z.boolean().refine((v) => v, 'Vous devez accepter les conditions générales.'),
    marketing_consent: z.boolean(),
  })
  .superRefine((v, ctx) => {
    if (v.fulfillment_type === 'delivery') {
      if (!v.zone_id) ctx.addIssue({ code: 'custom', path: ['zone_id'], message: 'Choisissez votre zone de livraison.' })
      if (!v.district || v.district.length < 2) ctx.addIssue({ code: 'custom', path: ['district'], message: 'Indiquez votre quartier.' })
    }
  })

type CheckoutForm = z.infer<typeof checkoutSchema>

const FIELD_MAP: Record<string, keyof CheckoutForm> = {
  'customer.first_name': 'first_name',
  'customer.last_name': 'last_name',
  'customer.phone': 'phone',
  'customer.email': 'email',
  'delivery.zone_id': 'zone_id',
  'delivery.district': 'district',
  'delivery.street': 'street',
  'delivery.landmark': 'landmark',
  slot_start_at: 'slot_start_at',
  payment_method: 'payment_method',
  accepted_terms: 'accepted_terms',
}

/** UX-05 — Commande : destinataire, adresse/zone, créneau, paiement, récapitulatif. */
export function CheckoutPage() {
  const { cart, headers, stored, count, reset } = useCart()
  const { me } = useAuth()
  const { data: config } = usePublicConfig()
  const { data: zones, isLoading: zonesLoading } = useZones()
  const navigate = useNavigate()
  // Clé d'idempotence stable pendant toute la saisie : un double clic ne crée qu'une commande.
  const [idempotencyKey] = useState(() => newIdempotencyKey('order'))
  const [submitting, setSubmitting] = useState(false)
  const [waLoading, setWaLoading] = useState(false)
  useSeo({ title: 'Finaliser la commande', noindex: true })

  const methods = useMemo(() => config?.payment_methods ?? [], [config])
  const pickupEnabled = config?.settings['ordering.pickup_enabled'] === true

  const form = useForm<CheckoutForm>({
    resolver: zodResolver(checkoutSchema),
    defaultValues: {
      first_name: me?.customer?.first_name ?? '',
      last_name: me?.customer?.last_name ?? '',
      phone: me?.customer?.phone ?? '',
      email: me?.email ?? '',
      fulfillment_type: 'delivery',
      zone_id: '',
      district: '',
      street: '',
      landmark: '',
      slot_start_at: '',
      payment_method: '',
      notes: '',
      accepted_terms: false,
      marketing_consent: false,
    },
  })
  const { register, handleSubmit, setValue, setError, formState } = form
  const [zoneId, fulfillment, slot, method] = useWatch({ control: form.control, name: ['zone_id', 'fulfillment_type', 'slot_start_at', 'payment_method'] })
  // Position de livraison confirmée par le client ; la zone (et les frais) en découlent côté serveur.
  const [location, setLocation] = useState<PickedLocation | null>(null)
  const [zoneFromPosition, setZoneFromPosition] = useState(false)
  const savedAddresses = useQuery({
    queryKey: ['account', 'addresses'],
    enabled: !!me?.customer,
    queryFn: async () => (await api.get<{ data: SavedAddress[] }>('/account/addresses')).data,
  })

  const onLocation = (loc: PickedLocation | null, res: GeoResolution | null) => {
    setLocation(loc)
    const fromPosition = !!loc && !!res?.zone && res.zone_source === 'position'
    setZoneFromPosition(fromPosition)
    if (fromPosition) setValue('zone_id', res!.zone!.id, { shouldValidate: true })
  }
  const [savedId, setSavedId] = useState('')
  const applySavedAddress = (id: string) => {
    setSavedId(id)
    const a = savedAddresses.data?.find((x) => x.id === id)
    if (!a) return
    setValue('zone_id', a.zone_id, { shouldValidate: true })
    setValue('district', a.district, { shouldValidate: true })
    setValue('street', a.street ?? '')
    setValue('landmark', a.landmark ?? '')
    setLocation(a.lat !== null && a.lng !== null ? { lat: a.lat, lng: a.lng, source: 'saved', accuracy_m: a.location_accuracy_m } : null)
    setZoneFromPosition(false)
  }

  useEffect(() => {
    if (methods.length && !method) setValue('payment_method', methods[0].code)
  }, [methods, method, setValue])

  const quoteQuery = useQuery({
    queryKey: ['quote', stored?.id, zoneId, fulfillment, cart?.promotion_code, cart?.subtotal_xof, location?.lat, location?.lng],
    enabled: !!stored && count > 0,
    queryFn: async () => {
      const position = fulfillment === 'delivery' && location ? { lat: location.lat, lng: location.lng } : {}
      return (await api.post<{ data: Quote }>('/checkout/quote', { zone_id: zoneId || null, fulfillment_type: fulfillment, ...position }, { headers: headers() })).data
    },
    placeholderData: (prev) => prev,
  })
  const quote = quoteQuery.data
  // Devis en cours de recalcul (zone, position ou panier modifiés) : le total affiché n'est pas encore le bon.
  const quoteRefreshing = quoteQuery.isFetching || quoteQuery.isPlaceholderData
  const slots = useMemo(() => quote?.slots ?? [], [quote])

  // Adresse par défaut du client présélectionnée une seule fois (modifiable ensuite).
  const defaultApplied = useRef(false)
  useEffect(() => {
    if (defaultApplied.current || !savedAddresses.data) return
    defaultApplied.current = true
    const def = savedAddresses.data.find((a) => a.is_default)
    // Synchronisation unique avec une donnée chargée (le carnet d'adresses), pas un état dérivé.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (def && !form.getValues('district')) applySavedAddress(def.id)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- application unique au chargement du carnet
  }, [savedAddresses.data])

  // La zone affichée suit TOUJOURS celle que le serveur a calculée depuis la position (ex. adresse enregistrée
  // dont la zone a été redessinée) : le client ne voit jamais une zone différente de celle facturée.
  const serverZone = fulfillment === 'delivery' && location && !quoteQuery.isPlaceholderData && quote?.zone_source === 'position' ? (quote.zone ?? null) : null
  const zoneLocked = zoneFromPosition || serverZone !== null
  useEffect(() => {
    if (serverZone && serverZone.id !== zoneId) setValue('zone_id', serverZone.id, { shouldValidate: true })
  }, [serverZone, zoneId, setValue])

  useEffect(() => {
    if (slot && slots.length && !slots.some((s) => s.start_at === slot)) setValue('slot_start_at', '')
  }, [slots, slot, setValue])

  if (!stored || count === 0) {
    return (
      <div className="container-page py-16">
        <EmptyState icon={<ShoppingBag className="size-6" />} title="Votre panier est vide" action={<ButtonLink to="/boutique">Voir la boutique</ButtonLink>} />
      </div>
    )
  }

  const applyServerErrors = (error: unknown) => {
    if (!(error instanceof ApiError)) return toast.error(errorMessage(error))
    const fields = error.fieldErrors
    Object.entries(fields).forEach(([key, messages]) => {
      const field = FIELD_MAP[key]
      if (field) setError(field, { message: messages[0] })
    })
    if (error.code === 'invalid_phone') setError('phone', { message: error.message })
    if (error.code === 'slot_unavailable') {
      setValue('slot_start_at', '')
      quoteQuery.refetch()
    }
    if (error.code === 'total_changed') {
      // Le montant a changé depuis l'affichage (frais de zone modifiés) : rien n'est créé, nouveau total affiché.
      quoteQuery.refetch()
      return toast.warning(error.message)
    }
    if (error.code === 'cart_converted') {
      const ref = (error.details as { reference?: string }).reference
      toast.info(`Ce panier correspond déjà à la commande ${ref ?? ''}.`)
      reset()
      return
    }
    toast.error(error.message)
  }

  const onSubmit = handleSubmit(async (values) => {
    setSubmitting(true)
    try {
      const payload = {
        customer: { first_name: values.first_name, last_name: values.last_name || null, phone: values.phone, email: values.email || null },
        fulfillment_type: values.fulfillment_type,
        delivery:
          values.fulfillment_type === 'delivery'
            ? {
                zone_id: values.zone_id,
                district: values.district,
                street: values.street || null,
                landmark: values.landmark || null,
                ...(location ? { lat: location.lat, lng: location.lng, location_source: location.source, accuracy_m: location.accuracy_m ?? null } : {}),
              }
            : null,
        // Le client valide le total qu'il voit : s'il a changé, le serveur refuse au lieu de facturer autre chose.
        expected_total_xof: quote?.total_xof,
        slot_start_at: values.slot_start_at,
        payment_method: values.payment_method || null,
        notes: values.notes || null,
        accepted_terms: values.accepted_terms,
        marketing_consent: values.marketing_consent,
      }
      const order = (await api.post<{ data: PublicOrder }>('/checkout/orders', payload, { headers: { ...headers(), 'Idempotency-Key': idempotencyKey } })).data
      orderStorage.save(order.reference, order.tracking_token!)
      reset()
      const online = methods.find((m) => m.code === order.payment_method)?.online
      navigate(online ? `/paiement/${order.reference}?t=${encodeURIComponent(order.tracking_token!)}&auto=1` : `/commande/${order.reference}/confirmation?t=${encodeURIComponent(order.tracking_token!)}`)
    } catch (error) {
      applyServerErrors(error)
    } finally {
      setSubmitting(false)
    }
  })

  const sendWhatsApp = async () => {
    setWaLoading(true)
    // Ouverture synchrone de l'onglet pour éviter le blocage des fenêtres surgissantes.
    const win = window.open('about:blank', '_blank')
    try {
      const { data } = await api.post<{ data: { reference: string; whatsapp_url: string | null; tracking_token: string | null; notice: string } }>(
        '/checkout/whatsapp-draft',
        { zone_id: zoneId || null, first_name: form.getValues('first_name') || null },
        { headers: headers() },
      )
      if (data.tracking_token) orderStorage.save(data.reference, data.tracking_token)
      if (data.whatsapp_url && win) win.location.href = data.whatsapp_url
      else {
        win?.close()
        toast.error("Le numéro WhatsApp de MK Délices n'est pas encore configuré.")
      }
      toast.info(`Brouillon ${data.reference} préparé`, { description: data.notice, duration: 8000 })
    } catch (error) {
      win?.close()
      toast.error(errorMessage(error))
    } finally {
      setWaLoading(false)
    }
  }

  const blocking = quote?.issues ?? []
  const groupedSlots = slots.reduce<Record<string, Slot[]>>((acc, s) => {
    const day = s.label.split(' · ')[0]
    ;(acc[day] ??= []).push(s)
    return acc
  }, {})

  return (
    <div className="container-page py-8 sm:py-10">
      <h1 className="text-3xl font-semibold sm:text-4xl">Finaliser la commande</h1>
      <p className="mt-2 text-cocoa-500">
        Pas besoin de compte. {!me && <Link to="/connexion?redirect=/commander" className="font-medium text-raspberry-600 underline">Déjà client ? Se connecter</Link>}
      </p>

      <form onSubmit={onSubmit} noValidate className="mt-8 grid gap-8 lg:grid-cols-[1fr_400px]">
        <div className="space-y-6">
          <Card className="p-5 sm:p-6">
            <h2 className="mb-4 text-xl font-semibold">1. Destinataire</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Prénom" required error={formState.errors.first_name?.message}>
                {(p) => <Input {...p} {...register('first_name')} autoComplete="given-name" />}
              </Field>
              <Field label="Nom" error={formState.errors.last_name?.message}>
                {(p) => <Input {...p} {...register('last_name')} autoComplete="family-name" />}
              </Field>
              <Field label="Téléphone" required error={formState.errors.phone?.message} hint="Le livreur vous appellera sur ce numéro.">
                {(p) => <Input {...p} {...register('phone')} type="tel" inputMode="tel" autoComplete="tel" placeholder="77 123 45 67" />}
              </Field>
              <Field label="Email (facultatif)" error={formState.errors.email?.message} hint="Pour recevoir le suivi par email.">
                {(p) => <Input {...p} {...register('email')} type="email" autoComplete="email" />}
              </Field>
            </div>
          </Card>

          <Card className="p-5 sm:p-6">
            <h2 className="mb-4 flex items-center gap-2 text-xl font-semibold">
              <Truck className="size-5 text-caramel-600" aria-hidden /> 2. Livraison
            </h2>
            {pickupEnabled && (
              <div className="mb-4 grid grid-cols-2 gap-2" role="radiogroup" aria-label="Mode de remise">
                {(['delivery', 'pickup'] as const).map((f) => (
                  <label key={f} className={cn('has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-raspberry-500 flex min-h-12 cursor-pointer items-center justify-center rounded-[var(--radius-control)] border-2 font-medium', fulfillment === f ? 'border-cocoa-700 bg-cream-50' : 'border-cocoa-300')}>
                    <input type="radio" value={f} {...register('fulfillment_type')} className="sr-only" />
                    {f === 'delivery' ? 'Livraison' : 'Retrait sur place'}
                  </label>
                ))}
              </div>
            )}
            {fulfillment === 'delivery' && (
              <div className="grid gap-4 sm:grid-cols-2">
                {(savedAddresses.data?.length ?? 0) > 0 && (
                  <Field label="Mes adresses" className="sm:col-span-2">
                    {(p) => (
                      <Select {...p} value={savedId} onChange={(e) => applySavedAddress(e.target.value)}>
                        <option value="">Choisir une adresse enregistrée…</option>
                        {savedAddresses.data!.map((a) => (
                          <option key={a.id} value={a.id}>
                            {a.label || a.district} — {a.district}
                            {a.is_default ? ' (par défaut)' : ''}
                          </option>
                        ))}
                      </Select>
                    )}
                  </Field>
                )}
                <div className="sm:col-span-2">
                  <LocationPicker
                    value={location}
                    onChange={onLocation}
                    zoneId={zoneId}
                    zones={zones}
                    onSuggestion={(place) => {
                      if (place.district && !form.getValues('district')) setValue('district', place.district, { shouldValidate: true })
                      if (place.street && !form.getValues('street')) setValue('street', place.street)
                    }}
                  />
                </div>
                <Field
                  label="Zone de livraison"
                  required
                  error={formState.errors.zone_id?.message}
                  hint={zoneLocked ? 'Déterminée par votre position. Retirez la position pour la choisir vous-même.' : undefined}
                  className="sm:col-span-2"
                >
                  {(p) =>
                    zoneLocked ? (
                      <Input {...p} readOnly value={zones?.find((z) => z.id === zoneId)?.name ?? ''} />
                    ) : (
                      <Select {...p} {...register('zone_id')} disabled={zonesLoading}>
                      <option value="">Choisir votre zone…</option>
                      {zones?.map((z) => (
                        <option key={z.id} value={z.id}>
                          {z.name} — {formatXof(z.delivery_fee_xof)}
                        </option>
                      ))}
                      </Select>
                    )
                  }
                </Field>
                <Field label="Quartier" required error={formState.errors.district?.message}>
                  {(p) => <Input {...p} {...register('district')} autoComplete="address-level3" />}
                </Field>
                <Field label="Rue / immeuble" error={formState.errors.street?.message}>
                  {(p) => <Input {...p} {...register('street')} autoComplete="street-address" />}
                </Field>
                <Field label="Point de repère" error={formState.errors.landmark?.message} hint="Ex. : à côté de la pharmacie, portail bleu…" className="sm:col-span-2">
                  {(p) => <Input {...p} {...register('landmark')} />}
                </Field>
              </div>
            )}
          </Card>

          <Card className="p-5 sm:p-6">
            <h2 className="mb-1 flex items-center gap-2 text-xl font-semibold">
              <CalendarClock className="size-5 text-caramel-600" aria-hidden /> 3. Créneau
            </h2>
            <p className="mb-4 text-sm text-cocoa-500">Créneaux disponibles selon le temps de préparation de votre commande.</p>
            {quoteQuery.isError && !quote ? (
              <ErrorState error={quoteQuery.error} onRetry={() => quoteQuery.refetch()} />
            ) : quoteQuery.isLoading ? (
              <Skeleton className="h-28" />
            ) : slots.length === 0 ? (
              <Notice tone="warning">Aucun créneau disponible pour le moment. Contactez-nous par WhatsApp.</Notice>
            ) : (
              <fieldset aria-describedby={formState.errors.slot_start_at ? 'slot-error' : undefined}>
                <legend className="sr-only">Créneau de livraison</legend>
                <div className="space-y-4">
                  {Object.entries(groupedSlots)
                    .slice(0, 5)
                    .map(([day, daySlots]) => (
                      <div key={day}>
                        <p className="mb-2 text-sm font-semibold capitalize">{day}</p>
                        <div className="flex flex-wrap gap-2">
                          {daySlots.map((s) => {
                            const active = slot === s.start_at
                            return (
                              <label key={s.start_at} className={cn('has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-raspberry-500 flex min-h-11 cursor-pointer items-center rounded-full border-2 px-4 text-sm font-medium transition', active ? 'border-cocoa-700 bg-cocoa-700 text-cream-50' : 'border-cocoa-300 bg-white hover:border-cocoa-500')}>
                                <input type="radio" value={s.start_at} {...register('slot_start_at')} className="sr-only" />
                                {s.label.split(' · ')[1]}
                                {s.remaining <= 2 && <span className={cn('ml-2 text-xs', active ? 'text-caramel-300' : 'text-raspberry-600')}>{s.remaining} place{s.remaining > 1 ? 's' : ''}</span>}
                              </label>
                            )
                          })}
                        </div>
                      </div>
                    ))}
                </div>
                {formState.errors.slot_start_at && (
                  <p id="slot-error" role="alert" className="mt-2 text-sm font-medium text-error-600">
                    {formState.errors.slot_start_at.message}
                  </p>
                )}
              </fieldset>
            )}
            <div className="mt-4">
              <Field label="Consignes pour la commande (facultatif)" error={formState.errors.notes?.message}>
                {(p) => <Textarea {...p} {...register('notes')} maxLength={500} placeholder="Message sur le gâteau, précision de livraison…" />}
              </Field>
            </div>
          </Card>

          <Card className="p-5 sm:p-6">
            <h2 className="mb-4 flex items-center gap-2 text-xl font-semibold">
              <CreditCard className="size-5 text-caramel-600" aria-hidden /> 4. Paiement
            </h2>
            {methods.length === 0 ? (
              <Notice tone="info" title="Paiement à convenir">
                Le paiement en ligne n'est pas encore ouvert. MK Délices vous contactera pour confirmer la commande et le règlement.
              </Notice>
            ) : (
              <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Moyen de paiement">
                {methods.map((m) => (
                  <label key={m.code} className={cn('flex min-h-14 cursor-pointer items-center gap-3 rounded-[var(--radius-control)] border-2 bg-white px-4 py-3', method === m.code ? 'border-cocoa-700 shadow-soft' : 'border-cocoa-100')}>
                    <input type="radio" value={m.code} {...register('payment_method')} className="size-4 accent-cocoa-700" />
                    <span>
                      <span className="block font-medium">{PAYMENT_LABELS[m.code] ?? m.label}</span>
                      <span className="text-xs text-cocoa-500">{m.test_only ? 'Environnement de test — aucun débit réel' : m.online ? 'Paiement sécurisé chez le prestataire' : 'Règlement à la remise'}</span>
                    </span>
                  </label>
                ))}
              </div>
            )}
          </Card>
        </div>

        <aside className="lg:sticky lg:top-24 lg:self-start">
          <Card className="p-5 sm:p-6">
            <h2 className="mb-4 text-xl font-semibold">Récapitulatif</h2>
            {/* Audit M11 : un devis en échec s'explique et se relance, au lieu d'un chargement sans fin. */}
            {!quote && quoteQuery.isError ? (
              <ErrorState error={quoteQuery.error} onRetry={() => quoteQuery.refetch()} />
            ) : !quote ? (
              <Skeleton className="h-40" />
            ) : (
              <>
                {blocking.map((i) => (
                  <Notice key={i.code + i.message} tone="warning" className="mb-3">
                    {i.message}
                  </Notice>
                ))}
                <Summary quote={quote} />
                {quote.lines.some((l) => l.allergens.length) && (
                  <p className="mt-4 flex gap-2 rounded-[var(--radius-control)] bg-warning-50 p-3 text-xs text-warning-600">
                    <AlertCircle className="size-4 shrink-0" aria-hidden />
                    Allergènes présents : {[...new Set(quote.lines.flatMap((l) => l.allergens))].map(allergenLabel).join(', ')}.
                  </p>
                )}
              </>
            )}
            <div className="mt-5 space-y-1">
              <Checkbox
                {...register('accepted_terms')}
                error={formState.errors.accepted_terms?.message}
                label={
                  <>
                    J'accepte les{' '}
                    <Link to="/conditions-generales" target="_blank" className="font-medium underline">
                      conditions générales
                    </Link>{' '}
                    et la{' '}
                    <Link to="/confidentialite" target="_blank" className="font-medium underline">
                      politique de confidentialité
                    </Link>
                    .
                  </>
                }
              />
              <Checkbox {...register('marketing_consent')} label="J'accepte de recevoir les offres de MK Délices (facultatif)." />
            </div>
            {/* Validation bloquée pendant le recalcul du devis : le client valide toujours le total affiché. */}
            <Button type="submit" size="lg" className="mt-4 w-full" loading={submitting} disabled={!quote || quoteRefreshing || blocking.length > 0} aria-describedby={blocking.length ? 'checkout-blocking' : undefined}>
              Confirmer · {quote && !quoteRefreshing ? formatXof(quote.total_xof) : '…'}
            </Button>
            {blocking.length > 0 ? (
              <p id="checkout-blocking" className="mt-2 text-center text-xs font-medium text-warning-600">
                Corrigez le point signalé ci-dessus pour confirmer.
              </p>
            ) : (
              <p className="mt-2 text-center text-xs text-cocoa-500">Votre commande sera confirmée par MK Délices.</p>
            )}

            <div className="mt-5 border-t border-cocoa-100/70 pt-5">
              <p className="mb-2 text-sm text-cocoa-700">Vous préférez échanger avec nous ?</p>
              <Button type="button" variant="whatsapp" className="w-full" onClick={sendWhatsApp} loading={waLoading} icon={<MessageCircle className="size-5" />}>
                Envoyer mon panier sur WhatsApp
              </Button>
              <p className="mt-2 text-xs text-cocoa-500">Un brouillon référencé est créé ; il ne vaut ni confirmation ni paiement.</p>
            </div>
          </Card>
        </aside>
      </form>
    </div>
  )
}

