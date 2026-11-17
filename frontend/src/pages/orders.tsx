import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Circle, Clock, CreditCard, FileText, FlaskConical, Loader2, MessageCircle, PackageCheck, ShieldCheck, Star, Undo2, XCircle } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { Button, buttonClass, ButtonLink } from '@/components/ui/Button'
import { Card, Modal, Price, StatusPill } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Spinner } from '@/components/ui/Feedback'
import { Field, Textarea } from '@/components/ui/Form'
import { InvoiceList } from '@/features/invoices/InvoiceList'
import { usePublicConfig } from '@/hooks/usePublicData'
import { useSeo } from '@/hooks/useSeo'
import { api, ApiError, newIdempotencyKey } from '@/lib/api'
import { cn } from '@/lib/cn'
import { allergenLabel, formatDateTime, formatSlot, formatXof, PAYMENT_LABELS } from '@/lib/format'
import { ORDER_TOKEN_TTL_DAYS, orderStorage, trackingUrl } from '@/lib/storage'
import type { PublicOrder } from '@/types/api'

/** Jeton de suivi : paramètre ?t= (lien reçu) ou mémoire locale de cet appareil. */
function useTrackingToken(reference: string): string | null {
  const [params, setParams] = useSearchParams()
  const fromUrl = params.get('t')
  useEffect(() => {
    if (fromUrl) {
      orderStorage.save(reference, fromUrl)
      const next = new URLSearchParams(params)
      next.delete('t')
      setParams(next, { replace: true })
    }
  }, [fromUrl, reference, params, setParams])
  return fromUrl ?? orderStorage.get(reference)?.token ?? null
}

function trackingHeaders(token: string | null): Record<string, string> {
  return token ? { 'X-Tracking-Token': token } : {}
}

function useOrder(reference: string, token: string | null, refetchInterval?: number | false) {
  return useQuery({
    queryKey: ['order', reference],
    queryFn: async () => (await api.get<{ data: PublicOrder }>(`/orders/${reference}/status`, { headers: trackingHeaders(token) })).data,
    retry: false,
    refetchInterval,
  })
}

function OrderNotFound({ error }: { error: unknown }) {
  const expired = error instanceof ApiError && error.status === 404
  return (
    <div className="container-page max-w-xl py-16">
      {expired ? (
        <EmptyState
          title="Lien de suivi invalide ou expiré"
          description="Pour protéger vos informations, nous ne pouvons pas afficher cette commande. Contactez MK Délices avec votre référence : nous vérifierons votre identité."
          action={<ButtonLink to="/contact">Nous contacter</ButtonLink>}
        />
      ) : (
        <ErrorState error={error} />
      )}
    </div>
  )
}

export function ConfirmationPage() {
  const { reference = '' } = useParams()
  const token = useTrackingToken(reference)
  const { data: order, isLoading, error } = useOrder(reference, token)
  useSeo({ title: 'Commande enregistrée', noindex: true })

  if (isLoading) return <Spinner />
  if (error || !order) return <OrderNotFound error={error} />
  const link = token ? `${window.location.origin}${trackingUrl(order.reference, token)}` : null

  return (
    <div className="container-page max-w-2xl py-12 text-center">
      <div className="mx-auto grid size-16 place-items-center rounded-full bg-success-50 text-success-600">
        <CheckCircle2 className="size-9" aria-hidden />
      </div>
      <h1 className="mt-5 text-4xl font-semibold">Merci {order.first_name} !</h1>
      <p className="mt-3 text-lg text-cocoa-700">
        Votre commande <strong>{order.reference}</strong> est enregistrée.
      </p>
      <p className="mt-1 text-cocoa-500">MK Délices va la confirmer très prochainement. {order.amount_due_xof > 0 && order.can_pay && 'Le paiement reste à effectuer.'}</p>

      <Card className="mt-8 p-6 text-left">
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-cocoa-500">Livraison</dt>
            <dd className="font-medium">{formatSlot(order.slot_start_at, order.slot_end_at)}</dd>
          </div>
          <div>
            <dt className="text-cocoa-500">Adresse</dt>
            <dd className="font-medium">{[order.district, order.zone_name].filter(Boolean).join(', ') || 'Retrait sur place'}</dd>
          </div>
          <div>
            <dt className="text-cocoa-500">Total</dt>
            <dd className="font-medium">{formatXof(order.total_xof)}</dd>
          </div>
          <div>
            <dt className="text-cocoa-500">Paiement</dt>
            <dd>
              <StatusPill status={order.payment_status} label={order.payment_status_label} />
            </dd>
          </div>
        </dl>
        {link && (
          <div className="mt-5 rounded-[var(--radius-control)] bg-cream-100 p-4">
            <p className="text-sm font-semibold">Votre lien de suivi personnel</p>
            <p className="mt-1 text-xs text-cocoa-500">Gardez-le précieusement : il donne accès au suivi de cette commande. Ne le partagez pas.</p>
            <Button
              variant="secondary"
              size="sm"
              className="mt-3"
              onClick={() => navigator.clipboard?.writeText(link).then(() => toast.success('Lien copié'))}
            >
              Copier le lien de suivi
            </Button>
          </div>
        )}
      </Card>
      <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
        {order.can_pay && order.amount_due_xof > 0 && order.payment_method && order.payment_method !== 'cash_on_delivery' && (
          <ButtonLink to={`/paiement/${order.reference}`} size="lg" icon={<CreditCard className="size-5" />}>
            Payer {formatXof(order.amount_due_xof)}
          </ButtonLink>
        )}
        <ButtonLink to={`/suivi/${order.reference}`} variant="secondary" size="lg">
          Suivre ma commande
        </ButtonLink>
      </div>
    </div>
  )
}

/** UX-06 — Paiement : redirection vers le prestataire ; la confirmation vient uniquement du serveur. */
export function PaymentPage() {
  const { reference = '' } = useParams()
  const [params] = useSearchParams()
  const token = useTrackingToken(reference)
  const { data: order, isLoading, error } = useOrder(reference, token)
  const { data: config } = usePublicConfig()
  const online = useMemo(() => (config?.payment_methods ?? []).filter((m) => m.online), [config])
  const [chosenMethod, setMethod] = useState<string | null>(null)
  const keyRef = useRef(newIdempotencyKey('pay'))
  const autoStarted = useRef(false)
  useSeo({ title: 'Paiement', noindex: true })

  const method = chosenMethod ?? (order ? (online.some((m) => m.code === order.payment_method) ? order.payment_method : (online[0]?.code ?? null)) : null)

  const pay = useMutation({
    mutationFn: async (code: string) =>
      (await api.post<{ data: { checkout_url: string | null } }>(`/orders/${reference}/payments`, { method: code }, { headers: { ...trackingHeaders(token), 'Idempotency-Key': keyRef.current } })).data,
    onSuccess: (data) => {
      if (data.checkout_url) window.location.assign(data.checkout_url)
      else toast.error('Le prestataire n\'a pas fourni de page de paiement.')
    },
    onError: (e) => {
      keyRef.current = newIdempotencyKey('pay')
      toast.error(errorMessage(e))
    },
  })

  useEffect(() => {
    if (params.get('auto') === '1' && order?.can_pay && method && !autoStarted.current) {
      autoStarted.current = true
      pay.mutate(method)
    }
  }, [params, order, method, pay])

  if (isLoading) return <Spinner />
  if (error || !order) return <OrderNotFound error={error} />

  if (!order.can_pay || order.amount_due_xof <= 0) {
    return (
      <div className="container-page max-w-xl py-16">
        <EmptyState title={order.payment_status === 'paid' ? 'Commande déjà payée' : 'Paiement non disponible'} description={`Statut du paiement : ${order.payment_status_label}.`} action={<ButtonLink to={`/suivi/${reference}`}>Suivre la commande</ButtonLink>} />
      </div>
    )
  }

  return (
    <div className="container-page max-w-xl py-12">
      <h1 className="text-3xl font-semibold">Paiement de la commande</h1>
      <p className="mt-1 text-cocoa-500">{order.reference}</p>
      <Card className="mt-6 p-6">
        <p className="text-sm text-cocoa-500">Montant à régler</p>
        <p className="font-display text-4xl font-semibold">
          <Price amount={order.amount_due_xof} />
        </p>
        {order.payment_status === 'pending' && (
          <Notice tone="info" className="mt-4">
            Un paiement est déjà en cours de vérification. Vous pouvez reprendre la session ouverte.
          </Notice>
        )}
        {online.length === 0 ? (
          <Notice tone="warning" className="mt-5" title="Paiement en ligne indisponible">
            Aucun moyen de paiement en ligne n'est actif pour le moment. MK Délices vous contactera pour le règlement.
          </Notice>
        ) : (
          <div className="mt-5 grid gap-2" role="radiogroup" aria-label="Moyen de paiement">
            {online.map((m) => (
              <label key={m.code} className={cn('flex min-h-14 cursor-pointer items-center gap-3 rounded-[var(--radius-control)] border-2 bg-white px-4', method === m.code ? 'border-cocoa-700' : 'border-cocoa-100')}>
                <input type="radio" name="method" checked={method === m.code} onChange={() => setMethod(m.code)} className="size-4 accent-cocoa-700" />
                <span className="font-medium">{PAYMENT_LABELS[m.code] ?? m.label}</span>
                {m.test_only && <span className="ml-auto text-xs text-warning-600">Test</span>}
              </label>
            ))}
          </div>
        )}
        <Button size="lg" className="mt-6 w-full" disabled={!method} loading={pay.isPending} onClick={() => method && pay.mutate(method)}>
          Payer {formatXof(order.amount_due_xof)}
        </Button>
        <p className="mt-3 flex items-center justify-center gap-1.5 text-xs text-cocoa-500">
          <ShieldCheck className="size-4 text-success-600" aria-hidden /> Votre paiement n'est validé qu'après confirmation du prestataire.
        </p>
      </Card>
    </div>
  )
}

/** Retour du prestataire : on ne conclut jamais au succès sur la seule base de cette page (T-009). */
export function PaymentReturnPage() {
  const { reference = '' } = useParams()
  const token = useTrackingToken(reference)
  const queryClient = useQueryClient()
  const [attempts, setAttempts] = useState(0)
  const { data: order, error, isLoading } = useQuery({
    queryKey: ['order', reference, 'refresh', attempts],
    queryFn: async () => (await api.post<{ data: PublicOrder }>(`/orders/${reference}/payments/refresh`, {}, { headers: trackingHeaders(token) })).data,
    retry: false,
  })
  useSeo({ title: 'Vérification du paiement', noindex: true })

  useEffect(() => {
    if (order?.payment_status === 'pending' && attempts < 12) {
      const t = setTimeout(() => setAttempts((a) => a + 1), 5000)
      return () => clearTimeout(t)
    }
    if (order) queryClient.setQueryData(['order', reference], order)
  }, [order, attempts, queryClient, reference])

  if (isLoading && !order) return <Spinner label="Vérification auprès du prestataire…" />
  if (error || !order) return <OrderNotFound error={error} />

  const state = order.payment_status
  // Chaque état financier a son message : seul « pending » signifie une vérification en cours.
  const view: Record<string, { icon: ReactNode; title: string; text: string }> = {
    paid: { icon: <CheckCircle2 className="mx-auto size-14 text-success-600" aria-hidden />, title: 'Paiement confirmé', text: 'Le prestataire a confirmé votre paiement. Merci !' },
    failed: { icon: <XCircle className="mx-auto size-14 text-error-600" aria-hidden />, title: 'Paiement non abouti', text: 'Le paiement a échoué ou a été annulé. Votre commande est conservée : vous pouvez réessayer.' },
    refunded: { icon: <Undo2 className="mx-auto size-14 text-cocoa-500" aria-hidden />, title: 'Commande remboursée', text: 'Le montant payé vous a été remboursé. Consultez le suivi de commande pour le détail.' },
    partially_refunded: { icon: <Undo2 className="mx-auto size-14 text-cocoa-500" aria-hidden />, title: 'Remboursement partiel', text: 'Une partie du montant payé vous a été remboursée. Consultez le suivi de commande pour le détail.' },
    unpaid: { icon: <Clock className="mx-auto size-14 text-caramel-600" aria-hidden />, title: 'Aucun paiement reçu', text: 'Aucun paiement n\'a été confirmé pour cette commande. Vous pouvez relancer le paiement depuis le suivi.' },
  }
  const current = view[state] ?? {
    icon: <Loader2 className="mx-auto size-14 animate-spin text-caramel-600" aria-hidden />,
    title: 'Paiement en vérification',
    text: 'Nous attendons la confirmation du prestataire. Cette page se met à jour automatiquement ; vous pouvez aussi revenir plus tard via votre lien de suivi.',
  }
  return (
    <div className="container-page max-w-xl py-16 text-center">
      {current.icon}
      <h1 className="mt-5 text-3xl font-semibold">{current.title}</h1>
      <p className="mt-3 text-cocoa-600" role="status">
        {current.text}
      </p>
      <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
        {(state === 'failed' || state === 'unpaid') && order.can_pay && <ButtonLink to={`/paiement/${reference}`}>Réessayer le paiement</ButtonLink>}
        <ButtonLink to={`/suivi/${reference}`} variant="secondary">
          Suivre ma commande
        </ButtonLink>
      </div>
    </div>
  )
}

/** Écran du prestataire SIMULÉ (environnement de test uniquement). */
export function SimulatorPage() {
  const { session = '' } = useParams()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const reference = params.get('ref') ?? ''
  const { data, isLoading, error } = useQuery({
    queryKey: ['simulator', session],
    queryFn: async () => (await api.get<{ data: { amount_xof: number; order_reference: string; status: string; notice: string } }>(`/dev/payment-simulator/${session}`)).data,
    retry: false,
  })
  const complete = useMutation({
    mutationFn: (outcome: 'success' | 'failure') => api.post(`/dev/payment-simulator/${session}/complete`, { outcome }),
    onSuccess: () => navigate(`/paiement/${reference || data?.order_reference}/retour`),
    onError: (e) => toast.error(errorMessage(e)),
  })
  useSeo({ title: 'Paiement simulé', noindex: true })

  if (isLoading) return <Spinner />
  if (error || !data) return <OrderNotFound error={error} />

  return (
    <div className="grid min-h-[70vh] place-items-center px-4 py-12">
      <Card className="w-full max-w-sm overflow-hidden">
        <div className="flex items-center gap-2 bg-warning-50 px-5 py-3 text-sm font-semibold text-warning-600">
          <FlaskConical className="size-4" aria-hidden /> Prestataire simulé — test
        </div>
        <div className="p-6 text-center">
          <p className="text-sm text-cocoa-500">Commande {data.order_reference}</p>
          <p className="mt-2 font-display text-4xl font-semibold">{formatXof(data.amount_xof)}</p>
          <p className="mt-3 text-xs text-cocoa-500">{data.notice} La confirmation passe par un webhook signé, comme avec un vrai prestataire.</p>
          {data.status !== 'pending' ? (
            <Notice tone="info" className="mt-5 text-left">
              Cette session est déjà traitée ({data.status}).
            </Notice>
          ) : (
            <div className="mt-6 grid gap-2">
              <Button onClick={() => complete.mutate('success')} loading={complete.isPending && complete.variables === 'success'}>
                Simuler un paiement réussi
              </Button>
              <Button variant="secondary" onClick={() => complete.mutate('failure')} loading={complete.isPending && complete.variables === 'failure'}>
                Simuler un échec
              </Button>
            </div>
          )}
        </div>
      </Card>
    </div>
  )
}

/** UX-07 — Suivi invité : étapes non sensibles, paiement, annulation, avis, contact. */
export function TrackingPage() {
  const { reference = '' } = useParams()
  const token = useTrackingToken(reference)
  const queryClient = useQueryClient()
  const { data: order, isLoading, error } = useOrder(reference, token, 60_000)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [reason, setReason] = useState('')
  useSeo({ title: `Suivi de commande ${reference}`, noindex: true })

  const cancel = useMutation({
    mutationFn: () => api.post<{ data: PublicOrder }>(`/orders/${reference}/cancel`, { reason }, { headers: trackingHeaders(token) }),
    onSuccess: (res) => {
      queryClient.setQueryData(['order', reference], res.data)
      setCancelOpen(false)
      toast.success('Commande annulée')
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  // L'onglet est ouvert SYNCHRONEMENT au clic (sinon bloqué comme fenêtre surgissante, iOS notamment),
  // puis dirigé vers WhatsApp une fois le message préparé ; refermé en cas d'erreur (audit lot 3).
  const whatsapp = useMutation({
    mutationFn: async (win: Window | null) => {
      const { data } = await api.post<{ data: { whatsapp_url: string | null } }>(`/orders/${reference}/whatsapp-draft`, {}, { headers: trackingHeaders(token) })
      return { url: data.whatsapp_url, win }
    },
    onSuccess: ({ url, win }) => {
      if (url && win) win.location.href = url
      else {
        win?.close()
        if (!url) toast.error('Numéro WhatsApp non configuré.')
        else window.location.href = url
      }
    },
    onError: (e, win) => {
      win?.close()
      toast.error(errorMessage(e))
    },
  })
  const contactWhatsApp = () => {
    const win = window.open('about:blank', '_blank')
    if (win) win.opener = null // l'onglet WhatsApp ne peut pas agir sur cette page
    whatsapp.mutate(win)
  }

  if (isLoading) return <Spinner />
  if (error || !order) return <OrderNotFound error={error} />
  const terminated = ['cancelled', 'rejected'].includes(order.status)

  return (
    <div className="container-page max-w-3xl py-10">
      <p className="text-sm text-cocoa-500">Commande du {formatDateTime(order.created_at)}</p>
      <div className="mt-1 flex flex-wrap items-center gap-3">
        <h1 className="text-3xl font-semibold">{order.reference}</h1>
        <StatusPill status={order.status} label={order.status_label} />
        <StatusPill status={order.payment_status} label={order.payment_status_label} />
      </div>

      {order.status === 'draft' && (
        <Notice tone="info" className="mt-6" title="Brouillon WhatsApp">
          Cette commande a été préparée via WhatsApp. Elle n'est pas encore confirmée : MK Délices revient vers vous dans la conversation.
        </Notice>
      )}
      {order.status === 'delivery_failed' && (
        <Notice tone="warning" className="mt-6" title="Livraison non aboutie">
          Notre équipe vous recontacte pour convenir d'une nouvelle livraison.
        </Notice>
      )}

      {!terminated && order.timeline.length > 0 && (
        <Card className="mt-8 p-6">
          <h2 className="sr-only">Étapes de la commande</h2>
          <ol className="relative space-y-5">
            {order.timeline.map((step, i) => {
              const current = step.done && !order.timeline[i + 1]?.done
              return (
                <li key={step.status} className="relative flex items-start gap-4">
                  {i < order.timeline.length - 1 && <span className={cn('absolute top-7 left-[13px] h-[calc(100%-4px)] w-0.5', step.done ? 'bg-success-600' : 'bg-cocoa-100')} aria-hidden />}
                  {step.done ? <CheckCircle2 className="relative size-7 shrink-0 text-success-600" aria-hidden /> : <Circle className="relative size-7 shrink-0 text-cocoa-100" aria-hidden />}
                  <div className="pt-0.5">
                    <p className={cn('font-medium', !step.done && 'text-cocoa-400')}>{step.label}</p>
                    {current && <p className="text-sm text-raspberry-600">Étape en cours</p>}
                    <span className="sr-only">{step.done ? 'terminée' : 'à venir'}</span>
                  </div>
                </li>
              )
            })}
          </ol>
        </Card>
      )}

      <div className="mt-6 grid gap-6 md:grid-cols-2">
        <Card className="p-6">
          <h2 className="flex items-center gap-2 font-semibold">
            <Clock className="size-4 text-caramel-600" aria-hidden /> Livraison
          </h2>
          <p className="mt-2 capitalize">{formatSlot(order.slot_start_at, order.slot_end_at)}</p>
          <p className="text-sm text-cocoa-500">{[order.district, order.zone_name].filter(Boolean).join(', ') || 'Retrait sur place'}</p>
        </Card>
        <Card className="p-6">
          <h2 className="flex items-center gap-2 font-semibold">
            <CreditCard className="size-4 text-caramel-600" aria-hidden /> Paiement
          </h2>
          <p className="mt-2">{order.payment_method ? (PAYMENT_LABELS[order.payment_method] ?? order.payment_method) : 'À convenir'}</p>
          <p className="text-sm text-cocoa-500">Reste à payer : {formatXof(order.amount_due_xof)}</p>
          {order.can_pay && order.amount_due_xof > 0 && order.payment_method !== 'cash_on_delivery' && (
            <ButtonLink to={`/paiement/${order.reference}`} size="sm" className="mt-3">
              Payer maintenant
            </ButtonLink>
          )}
        </Card>
      </div>

      {order.delivery_code && (
        <Card className="mt-6 flex items-center justify-between gap-4 border-2 border-caramel-500 p-5">
          <div>
            <h2 className="font-semibold">Code de remise</h2>
            <p className="text-sm text-cocoa-500">À donner au livreur à la réception de votre commande.</p>
          </div>
          <p className="font-display text-4xl tracking-[0.3em] tabular-nums" aria-label={`Code de remise ${order.delivery_code.split('').join(' ')}`}>
            {order.delivery_code}
          </p>
        </Card>
      )}

      <Card className="mt-6 p-6">
        <h2 className="flex items-center gap-2 font-semibold">
          <PackageCheck className="size-4 text-caramel-600" aria-hidden /> Articles
        </h2>
        <ul className="mt-3 divide-y divide-cocoa-100/70">
          {order.items.map((item, i) => (
            <li key={i} className="flex justify-between gap-4 py-3 text-sm">
              <div>
                <p className="font-medium">
                  {item.qty} × {item.product_name}
                </p>
                <p className="text-cocoa-500">{item.variant_label}</p>
                {item.allergens.length > 0 && <p className="text-xs text-cocoa-500">Allergènes : {item.allergens.map(allergenLabel).join(', ')}</p>}
              </div>
              <span className="tabular-nums">{formatXof(item.line_total_xof)}</span>
            </li>
          ))}
        </ul>
        <dl className="mt-3 space-y-1 border-t border-cocoa-100/70 pt-3 text-sm">
          <div className="flex justify-between">
            <dt>Sous-total</dt>
            <dd>{formatXof(order.subtotal_xof)}</dd>
          </div>
          {order.discount_xof > 0 && (
            <div className="flex justify-between text-success-600">
              <dt>Remise</dt>
              <dd>−{formatXof(order.discount_xof)}</dd>
            </div>
          )}
          <div className="flex justify-between">
            <dt>Livraison</dt>
            <dd>{formatXof(order.delivery_fee_xof)}</dd>
          </div>
          <div className="flex justify-between text-base font-semibold">
            <dt>Total</dt>
            <dd>{formatXof(order.total_xof)}</dd>
          </div>
        </dl>
      </Card>

      {(order.invoices ?? []).length > 0 && (
        <Card className="mt-6 p-6">
          <h2 className="flex items-center gap-2 font-semibold">
            <FileText className="size-4 text-caramel-600" aria-hidden /> Factures
          </h2>
          <p className="mt-1 text-sm text-cocoa-500">Votre facture est émise à la livraison ; un avoir accompagne chaque remboursement.</p>
          <div className="mt-3">
            <InvoiceList invoices={order.invoices} pathFor={(i) => `/orders/${order.reference}/invoices/${i.number}`} headers={trackingHeaders(token)} />
          </div>
        </Card>
      )}

      {order.can_review && <ReviewForm order={order} token={token} />}

      <div className="mt-8 flex flex-wrap gap-3">
        <Button variant="whatsapp" onClick={contactWhatsApp} loading={whatsapp.isPending} icon={<MessageCircle className="size-5" />}>
          Contacter MK Délices
        </Button>
        {order.can_cancel && (
          <Button variant="ghost" onClick={() => setCancelOpen(true)}>
            Annuler la commande
          </Button>
        )}
      </div>

      <Modal
        open={cancelOpen}
        onOpenChange={setCancelOpen}
        title="Annuler la commande ?"
        description="Possible tant que MK Délices ne l'a pas confirmée. Si un paiement a été encaissé, un remboursement sera instruit."
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setCancelOpen(false)}>
              Garder ma commande
            </Button>
            <Button variant="danger" disabled={reason.trim().length < 3} loading={cancel.isPending} onClick={() => cancel.mutate()}>
              Confirmer l'annulation
            </Button>
          </div>
        }
      >
        <Field label="Motif" required>
          {(p) => <Textarea {...p} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} />}
        </Field>
      </Modal>
    </div>
  )
}

function ReviewForm({ order, token }: { order: PublicOrder; token: string | null }) {
  const products = useMemo(() => [...new Map(order.items.map((i) => [i.product_id, i.product_name])).entries()], [order.items])
  const [productId, setProductId] = useState(products[0]?.[0] ?? '')
  const [stars, setStars] = useState(5)
  const [comment, setComment] = useState('')
  const [sent, setSent] = useState<string[]>([])
  const submit = useMutation({
    mutationFn: () => api.post(`/orders/${order.reference}/reviews`, { product_id: productId, stars, comment: comment || null }, { headers: trackingHeaders(token) }),
    onSuccess: () => {
      setSent((s) => [...s, productId])
      setComment('')
      toast.success('Merci ! Votre avis sera publié après modération.')
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <Card className="mt-6 p-6">
      <h2 className="flex items-center gap-2 font-semibold">
        <Star className="size-4 text-caramel-600" aria-hidden /> Votre avis compte
      </h2>
      <div className="mt-4 grid gap-4">
        <label className="text-sm">
          <span className="mb-1 block font-medium">Produit</span>
          <select className="min-h-11 w-full rounded-[var(--radius-control)] border border-cocoa-100 bg-white px-3" value={productId} onChange={(e) => setProductId(e.target.value)}>
            {products.map(([id, name]) => (
              <option key={id} value={id} disabled={sent.includes(id)}>
                {name}
                {sent.includes(id) ? ' (avis envoyé)' : ''}
              </option>
            ))}
          </select>
        </label>
        <fieldset>
          <legend className="mb-1 text-sm font-medium">Note</legend>
          <div className="flex gap-1">
            {[1, 2, 3, 4, 5].map((n) => (
              <button key={n} type="button" onClick={() => setStars(n)} className="grid size-11 place-items-center text-2xl" aria-label={`${n} étoile${n > 1 ? 's' : ''}`} aria-pressed={stars === n}>
                <span className={n <= stars ? 'text-caramel-500' : 'text-cocoa-100'}>★</span>
              </button>
            ))}
          </div>
        </fieldset>
        <Field label="Commentaire (facultatif)">{(p) => <Textarea {...p} value={comment} onChange={(e) => setComment(e.target.value)} maxLength={1000} />}</Field>
        <Button onClick={() => submit.mutate()} loading={submit.isPending} disabled={!productId || sent.includes(productId)} className="justify-self-start">
          Envoyer mon avis
        </Button>
      </div>
    </Card>
  )
}

/** Liste des commandes passées sur cet appareil (pas de recherche publique par numéro). */
export function TrackingLookupPage() {
  const [orders, setOrders] = useState(() => orderStorage.all())
  useSeo({ title: 'Suivre ma commande', noindex: true })
  return (
    <div className="container-page max-w-2xl py-12">
      <h1 className="text-4xl font-semibold">Suivre ma commande</h1>
      <p className="mt-3 text-cocoa-500">
        Utilisez le lien de suivi personnel reçu lors de votre commande. Pour votre sécurité, une commande ne peut pas être recherchée par son seul numéro.
      </p>
      {orders.length > 0 ? (
        <>
        <ul className="mt-8 space-y-3">
          {orders.map((o) => (
            <li key={o.reference}>
              <Link to={trackingUrl(o.reference, o.token)} className="flex items-center justify-between rounded-[var(--radius-card)] bg-white p-5 shadow-soft hover:shadow-lift">
                <span>
                  <span className="block font-semibold">{o.reference}</span>
                  <span className="text-sm text-cocoa-500">Commandée le {formatDateTime(o.createdAt)}</span>
                </span>
                <span className={buttonClass('secondary', 'sm')}>Voir le suivi</span>
              </Link>
            </li>
          ))}
        </ul>
        <div className="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm text-cocoa-500">
          <p>Les liens de suivi sont conservés {ORDER_TOKEN_TTL_DAYS} jours sur cet appareil.</p>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              orderStorage.clear()
              setOrders([])
              toast.success('Vos commandes ne sont plus mémorisées sur cet appareil.')
            }}
          >
            Oublier cet appareil
          </Button>
        </div>
        </>
      ) : (
        <EmptyState className="mt-8" title="Aucune commande sur cet appareil" description="Retrouvez vos commandes depuis le lien de suivi, ou créez un compte pour les centraliser." action={<ButtonLink to="/connexion">Se connecter</ButtonLink>} />
      )}
    </div>
  )
}
