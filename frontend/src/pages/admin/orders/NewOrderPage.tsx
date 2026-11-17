import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Minus, PhoneCall, Plus, Trash2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Card, PageHeader } from '@/components/ui/Display'
import { ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Field, Input, Select, Textarea } from '@/components/ui/Form'
import { usePublicConfig, useZones } from '@/hooks/usePublicData'
import { api, ApiError, newIdempotencyKey } from '@/lib/api'
import { formatXof } from '@/lib/format'
import { trackingUrl } from '@/lib/storage'
import type { Product, Quote } from '@/types/api'

interface WorkCart {
  cart_id: string
  cart_token: string
}

const EMPTY = { first_name: '', last_name: '', phone: '', email: '', district: '', street: '', landmark: '', notes: '', internal_notes: '' }

/**
 * Saisie d'une commande par l'équipe (téléphone, boutique). Le panier de travail et le devis passent par
 * les mêmes services que le site : prix, frais, créneaux, stock et promotions sont calculés par le serveur.
 */
export function NewOrderPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [channel, setChannel] = useState<'phone' | 'backoffice'>('phone')
  const [form, setForm] = useState(EMPTY)
  const [zoneId, setZoneId] = useState('')
  const [slot, setSlot] = useState('')
  const [method, setMethod] = useState('')
  const [promo, setPromo] = useState('')
  // Saisie du code promo : appliqué (nouveau devis) en quittant le champ ou sur Entrée, pas à chaque frappe.
  const [promoInput, setPromoInput] = useState('')
  const [variantId, setVariantId] = useState('')
  const [qty, setQty] = useState(1)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [idempotencyKey] = useState(() => newIdempotencyKey('bo'))

  const cart = useQuery({
    queryKey: ['admin', 'bo-cart', idempotencyKey],
    queryFn: async () => (await api.post<{ data: WorkCart }>('/cart')).data,
    staleTime: Infinity,
    gcTime: 0,
    retry: false,
  })
  const headers = useMemo(() => (cart.data ? { 'X-Cart-Id': cart.data.cart_id, 'X-Cart-Token': cart.data.cart_token } : undefined), [cart.data])

  const products = useQuery({
    queryKey: ['admin', 'bo-products'],
    queryFn: async () => (await api.get<{ data: Product[] }>('/catalog/products?per_page=100')).data,
    staleTime: 5 * 60_000,
  })
  const { data: zones } = useZones()
  const { data: config } = usePublicConfig()

  // Le panier n'est interrogé qu'après le premier ajout ; le nombre de lignes vient toujours du devis serveur.
  const [touched, setTouched] = useState(false)
  const quote = useQuery({
    queryKey: ['admin', 'bo-quote', cart.data?.cart_id, zoneId, promo],
    queryFn: async () => (await api.post<{ data: Quote }>('/checkout/quote', { zone_id: zoneId || null, promotion_code: promo.trim() || null }, { headers })).data,
    enabled: !!headers && touched,
    retry: false,
  })
  const refreshQuote = () => queryClient.invalidateQueries({ queryKey: ['admin', 'bo-quote'] })

  const addItem = useMutation({
    mutationFn: () => api.post('/cart/items', { variant_id: variantId, quantity: qty }, { headers }),
    onSuccess: () => {
      setTouched(true)
      setVariantId('')
      setQty(1)
      refreshQuote()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const changeItem = useMutation({
    mutationFn: ({ id, quantity }: { id: string; quantity: number }) =>
      quantity > 0 ? api.patch(`/cart/items/${id}`, { quantity }, { headers }) : api.delete(`/cart/items/${id}`, { headers }),
    onSuccess: () => refreshQuote(),
    onError: (e) => toast.error(errorMessage(e)),
  })

  const submit = useMutation({
    mutationFn: () =>
      api.post<{ data: { id: string; reference: string; tracking_token: string } }>(
        '/admin/orders',
        {
          channel,
          customer: { first_name: form.first_name, last_name: form.last_name || null, phone: form.phone, email: form.email || null },
          delivery: { zone_id: zoneId, district: form.district, street: form.street || null, landmark: form.landmark || null },
          slot_start_at: slot,
          payment_method: method || null,
          promotion_code: promo.trim() || null,
          notes: form.notes || null,
          internal_notes: form.internal_notes || null,
        },
        { headers: { ...headers, 'Idempotency-Key': idempotencyKey } },
      ),
    onSuccess: async ({ data }) => {
      const link = `${window.location.origin}${trackingUrl(data.reference, data.tracking_token)}`
      try {
        await navigator.clipboard.writeText(link)
        toast.success(`Commande ${data.reference} créée`, { description: 'Lien de suivi copié : envoyez-le au client (WhatsApp, SMS).' })
      } catch {
        toast.success(`Commande ${data.reference} créée`, { description: `Lien de suivi à envoyer au client : ${link}` })
      }
      queryClient.invalidateQueries({ queryKey: ['admin', 'orders'] })
      navigate(`/admin/commandes/${data.id}`)
    },
    onError: (e) => {
      setErrors(e instanceof ApiError ? e.fieldErrors : {})
      toast.error(errorMessage(e))
    },
  })

  const set = (key: keyof typeof EMPTY) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const err = (key: string) => errors[key]?.[0]

  if (cart.isLoading) return <Skeleton className="h-96" />
  if (cart.isError) return <ErrorState error={cart.error} onRetry={() => cart.refetch()} />

  // Panier vidé : le devis répond « cart_empty », ce n'est pas une erreur à afficher.
  const emptied = quote.error instanceof ApiError && quote.error.code === 'cart_empty'
  const q = emptied ? undefined : quote.data
  const itemCount = q?.lines.length ?? 0
  const variants = (products.data ?? []).flatMap((p) => (p.variants ?? []).filter((v) => v.available).map((v) => ({ id: v.id, label: `${p.name} – ${v.label} (${formatXof(v.price_xof)})` })))
  const slots = q?.slots ?? []
  const blocking = (q?.issues ?? []).filter((i) => i.code !== 'zone_required')
  const canSubmit = !!q && itemCount > 0 && !!zoneId && !!slot && form.first_name.trim().length >= 2 && form.phone.trim().length >= 9 && form.district.trim().length >= 2 && blocking.length === 0

  return (
    <div className="space-y-6">
      <Link to="/admin/commandes" className="inline-flex items-center gap-1.5 text-sm font-medium text-cocoa-500 hover:text-cocoa-800">
        <ArrowLeft className="size-4" /> Retour aux commandes
      </Link>
      <PageHeader eyebrow="Commandes" title="Nouvelle commande" description="Commande prise par téléphone ou à la boutique. Prix, frais de livraison et créneaux sont calculés comme sur le site." />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card className="space-y-4 p-5">
            <h2 className="text-lg font-semibold">1. Articles</h2>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <Field label="Produit" className="min-w-0 flex-1">
                {(p) => (
                  <Select {...p} value={variantId} onChange={(e) => setVariantId(e.target.value)} disabled={products.isLoading}>
                    <option value="">Choisir un produit…</option>
                    {variants.map((v) => (
                      <option key={v.id} value={v.id}>
                        {v.label}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <Field label="Quantité" className="w-28">
                {(p) => <Input {...p} type="number" min={1} max={50} value={qty} onChange={(e) => setQty(Math.max(1, Number(e.target.value) || 1))} />}
              </Field>
              <Button icon={<Plus className="size-4" />} disabled={!variantId} loading={addItem.isPending} onClick={() => addItem.mutate()}>
                Ajouter
              </Button>
            </div>

            {itemCount === 0 || !q ? (
              <p className="text-sm text-cocoa-500">{quote.isFetching ? 'Calcul…' : 'Aucun article pour le moment.'}</p>
            ) : (
              <>
                <ul className="divide-y divide-cocoa-100/70">
                  {q.lines.map((l) => (
                    <li key={l.item_id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                      <div className="min-w-0">
                        <p className="font-medium">
                          {l.product_name} <span className="text-cocoa-500">– {l.variant_label}</span>
                        </p>
                        <p className="text-xs text-cocoa-500">{formatXof(l.unit_price_xof)} / u.</p>
                      </div>
                      <div className="flex items-center gap-2">
                        <Button size="sm" variant="ghost" aria-label={`Retirer une unité de ${l.product_name}`} disabled={changeItem.isPending || quote.isFetching} onClick={() => changeItem.mutate({ id: l.item_id, quantity: l.quantity - 1 })} icon={<Minus className="size-4" />} />
                        <span className="w-8 text-center tabular-nums">{l.quantity}</span>
                        <Button size="sm" variant="ghost" aria-label={`Ajouter une unité de ${l.product_name}`} disabled={changeItem.isPending || quote.isFetching} onClick={() => changeItem.mutate({ id: l.item_id, quantity: l.quantity + 1 })} icon={<Plus className="size-4" />} />
                        <Button size="sm" variant="ghost" aria-label={`Supprimer ${l.product_name}`} disabled={changeItem.isPending} onClick={() => changeItem.mutate({ id: l.item_id, quantity: 0 })} icon={<Trash2 className="size-4" />} />
                        <span className="w-24 text-right font-semibold tabular-nums">{formatXof(l.line_total_xof)}</span>
                      </div>
                    </li>
                  ))}
                </ul>
                {blocking.length > 0 && (
                  <Notice tone="warning">
                    {blocking.map((i) => (
                      <p key={i.code + (i.message ?? '')}>{i.message}</p>
                    ))}
                  </Notice>
                )}
              </>
            )}
          </Card>

          <Card className="space-y-4 p-5">
            <h2 className="text-lg font-semibold">2. Client</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Prénom" required error={err('customer.first_name')}>{(p) => <Input {...p} value={form.first_name} onChange={set('first_name')} autoComplete="off" />}</Field>
              <Field label="Nom" error={err('customer.last_name')}>{(p) => <Input {...p} value={form.last_name} onChange={set('last_name')} autoComplete="off" />}</Field>
              <Field label="Téléphone" required hint="Numéro sénégalais, ex. 77 123 45 67" error={err('customer.phone')}>
                {(p) => <Input {...p} type="tel" value={form.phone} onChange={set('phone')} autoComplete="off" />}
              </Field>
              <Field label="Email" hint="Facultatif : confirmation et facture par email" error={err('customer.email')}>
                {(p) => <Input {...p} type="email" value={form.email} onChange={set('email')} autoComplete="off" />}
              </Field>
            </div>
          </Card>

          <Card className="space-y-4 p-5">
            <h2 className="text-lg font-semibold">3. Livraison</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Zone" required error={err('delivery.zone_id')}>
                {(p) => (
                  <Select
                    {...p}
                    value={zoneId}
                    onChange={(e) => {
                      setZoneId(e.target.value)
                      setSlot('')
                    }}
                  >
                    <option value="">Choisir…</option>
                    {(zones ?? []).map((z) => (
                      <option key={z.id} value={z.id}>
                        {z.name} — {formatXof(z.delivery_fee_xof)}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <Field label="Quartier" required error={err('delivery.district')}>{(p) => <Input {...p} value={form.district} onChange={set('district')} />}</Field>
              <Field label="Rue / adresse">{(p) => <Input {...p} value={form.street} onChange={set('street')} />}</Field>
              <Field label="Point de repère">{(p) => <Input {...p} value={form.landmark} onChange={set('landmark')} />}</Field>
              <Field label="Créneau" required className="sm:col-span-2" hint={!zoneId || itemCount === 0 ? 'Ajoutez des articles et choisissez la zone pour voir les créneaux.' : undefined} error={err('slot_start_at')}>
                {(p) => (
                  <Select {...p} value={slot} onChange={(e) => setSlot(e.target.value)} disabled={slots.length === 0}>
                    <option value="">{slots.length === 0 ? 'Aucun créneau disponible' : 'Choisir un créneau…'}</option>
                    {slots.map((s) => (
                      <option key={s.start_at} value={s.start_at}>
                        {s.label} ({s.remaining} place{s.remaining > 1 ? 's' : ''})
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <Field label="Consignes du client" className="sm:col-span-2" hint="Visibles par l'atelier et le livreur">
                {(p) => <Textarea {...p} rows={2} value={form.notes} onChange={set('notes')} />}
              </Field>
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card className="space-y-4 p-5 lg:sticky lg:top-24">
            <h2 className="text-lg font-semibold">4. Validation</h2>
            <Field label="Canal">
              {(p) => (
                <Select {...p} value={channel} onChange={(e) => setChannel(e.target.value as 'phone' | 'backoffice')}>
                  <option value="phone">Téléphone</option>
                  <option value="backoffice">Boutique / sur place</option>
                </Select>
              )}
            </Field>
            <Field label="Paiement" hint="Sans paiement en ligne, enregistrez l'encaissement depuis la commande.">
              {(p) => (
                <Select {...p} value={method} onChange={(e) => setMethod(e.target.value)}>
                  <option value="">Encaissement à enregistrer (espèces, transfert…)</option>
                  {(config?.payment_methods ?? []).filter((m) => m.online).map((m) => (
                    <option key={m.code} value={m.code}>
                      Lien de paiement : {m.label}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            <Field label="Code promo" hint="Appliqué en quittant le champ ou avec Entrée">
              {(p) => (
                <Input
                  {...p}
                  value={promoInput}
                  maxLength={40}
                  onChange={(e) => setPromoInput(e.target.value.toUpperCase())}
                  onBlur={() => setPromo(promoInput.trim())}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault()
                      setPromo(promoInput.trim())
                    }
                  }}
                />
              )}
            </Field>
            <Field label="Note interne" hint="Visible uniquement par l'équipe">
              {(p) => <Textarea {...p} rows={2} value={form.internal_notes} onChange={set('internal_notes')} />}
            </Field>

            <dl className="space-y-1.5 border-t border-cocoa-100 pt-4 text-sm">
              <div className="flex justify-between">
                <dt>Sous-total</dt>
                <dd className="tabular-nums">{formatXof(q?.subtotal_xof ?? 0)}</dd>
              </div>
              {(q?.discount_xof ?? 0) > 0 && (
                <div className="flex justify-between text-success-600">
                  <dt>Remise</dt>
                  <dd className="tabular-nums">−{formatXof(q?.discount_xof ?? 0)}</dd>
                </div>
              )}
              <div className="flex justify-between">
                <dt>Livraison</dt>
                <dd className="tabular-nums">{formatXof(q?.delivery_fee_xof ?? 0)}</dd>
              </div>
              <div className="flex justify-between text-base font-semibold">
                <dt>Total</dt>
                <dd className="tabular-nums">{formatXof(q?.total_xof ?? 0)}</dd>
              </div>
            </dl>
            <Button className="w-full" size="lg" icon={<PhoneCall className="size-5" />} disabled={!canSubmit} loading={submit.isPending} onClick={() => submit.mutate()}>
              Créer la commande
            </Button>
            <p className="text-xs text-cocoa-500">La commande est créée « À confirmer » : acceptez-la ensuite depuis sa fiche. Le lien de suivi est copié pour être envoyé au client.</p>
          </Card>
        </div>
      </div>
    </div>
  )
}
