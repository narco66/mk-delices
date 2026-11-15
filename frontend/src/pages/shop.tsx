import { useQuery } from '@tanstack/react-query'
import { ChevronRight, Clock, MessageCircle, Search, ShieldCheck, Snowflake, Star, Truck } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { ProductVisual } from '@/components/brand/Brand'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Badge, Price, QuantityStepper } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage } from '@/components/ui/Feedback'
import { Input, Select } from '@/components/ui/Form'
import { AllergyNotice, DemoCatalogNotice, ProductCard, ProductCardSkeleton, prepLabel, VariantPicker } from '@/features/catalog/components'
import { useCart } from '@/features/cart/CartContext'
import { useCategories, usePublicConfig, whatsappLink } from '@/hooks/usePublicData'
import { useSeo } from '@/hooks/useSeo'
import { api, toQuery } from '@/lib/api'
import { cn } from '@/lib/cn'
import type { Paginated, Product } from '@/types/api'

interface Filters {
  q?: string
  category?: string
  sort?: string
  available?: boolean
  signature?: boolean
}

function useProducts(filters: Filters) {
  return useQuery({
    queryKey: ['products', filters],
    queryFn: () => api.get<Paginated<Product>>(`/catalog/products${toQuery({ ...filters, per_page: 48 })}`),
    placeholderData: (prev) => prev,
  })
}

function ProductGrid({ filters, emptyAction }: { filters: Filters; emptyAction?: () => void }) {
  const { data, isLoading, error, refetch, isFetching } = useProducts(filters)
  const products = data?.data ?? []

  if (error) return <ErrorState error={error} onRetry={() => refetch()} />
  return (
    <div aria-busy={isFetching} className={cn('transition-opacity', isFetching && !isLoading && 'opacity-60')}>
      <DemoCatalogNotice show={products.some((p) => p.is_demo)} />
      {isLoading ? (
        <div className="mt-6 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3 xl:grid-cols-4">
          {Array.from({ length: 8 }, (_, i) => (
            <ProductCardSkeleton key={i} />
          ))}
        </div>
      ) : products.length === 0 ? (
        <EmptyState
          className="mt-6"
          icon={<Search className="size-6" />}
          title="Aucun résultat"
          description="Aucun produit ne correspond à votre recherche."
          action={emptyAction && <Button variant="secondary" onClick={emptyAction}>Réinitialiser les filtres</Button>}
        />
      ) : (
        <ul className="mt-6 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3 xl:grid-cols-4">
          {products.map((p, i) => (
            <li key={p.id} className="animate-fade-up" style={{ animationDelay: `${Math.min(i, 8) * 40}ms` }}>
              <ProductCard product={p} priority={i < 4} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** UX-02 — Boutique : recherche, filtres par catégorie, tri, grille 2 colonnes mobile. */
export function ShopPage() {
  const [params, setParams] = useSearchParams()
  const { data: categories } = useCategories()
  const [search, setSearch] = useState(params.get('q') ?? '')
  const filters: Filters = {
    q: params.get('q') ?? undefined,
    category: params.get('categorie') ?? undefined,
    sort: params.get('tri') ?? undefined,
    available: params.get('disponible') === '1' || undefined,
  }
  useSeo({ title: 'Boutique — tiramisus, gâteaux et douceurs', description: 'Tout le catalogue MK Délices : tiramisus, gâteaux, bonbons et desserts artisanaux livrés à Dakar.', canonicalPath: '/boutique' })

  useEffect(() => {
    const t = setTimeout(() => {
      const next = new URLSearchParams(params)
      if (search.trim()) next.set('q', search.trim())
      else next.delete('q')
      if (next.toString() !== params.toString()) setParams(next, { replace: true })
    }, 350)
    return () => clearTimeout(t)
  }, [search, params, setParams])

  const set = (key: string, value: string | null) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    setParams(next, { replace: true })
  }

  return (
    <div className="container-page py-10">
      <header className="mb-8 max-w-2xl">
        <p className="text-xs font-semibold tracking-[0.2em] text-raspberry-500 uppercase">La boutique</p>
        <h1 className="mt-2 text-4xl font-semibold sm:text-5xl">Toutes nos douceurs</h1>
        <p className="mt-3 text-cocoa-500">Préparées à la main, sur commande, et livrées à Dakar au créneau de votre choix.</p>
      </header>

      <div className="sticky top-[72px] z-20 -mx-4 space-y-3 bg-cream-100/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-[var(--radius-card)] sm:px-0">
        <div className="flex flex-col gap-3 sm:flex-row">
          <label className="relative flex-1">
            <span className="sr-only">Rechercher un produit</span>
            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-cocoa-400" aria-hidden />
            <Input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Rechercher un tiramisu, un gâteau…" className="pl-10" />
          </label>
          <div className="flex gap-2">
            <label className="flex-1 sm:w-48">
              <span className="sr-only">Trier par</span>
              <Select value={filters.sort ?? 'featured'} onChange={(e) => set('tri', e.target.value === 'featured' ? null : e.target.value)}>
                <option value="featured">Nos coups de cœur</option>
                <option value="price_asc">Prix croissant</option>
                <option value="price_desc">Prix décroissant</option>
                <option value="newest">Nouveautés</option>
                <option value="name">Nom A→Z</option>
              </Select>
            </label>
            <button
              type="button"
              aria-pressed={!!filters.available}
              onClick={() => set('disponible', filters.available ? null : '1')}
              className={cn('min-h-11 rounded-[var(--radius-control)] border px-3 text-sm font-medium whitespace-nowrap', filters.available ? 'border-cocoa-700 bg-cocoa-700 text-cream-50' : 'border-cocoa-100 bg-white')}
            >
              Disponibles
            </button>
          </div>
        </div>
        <div className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0" role="group" aria-label="Catégories">
          {[{ slug: '', name: 'Tout' }, ...(categories ?? [])].map((c) => {
            const active = (filters.category ?? '') === c.slug
            return (
              <button
                key={c.slug || 'all'}
                type="button"
                aria-pressed={active}
                onClick={() => set('categorie', c.slug || null)}
                className={cn('min-h-10 shrink-0 rounded-full px-4 text-sm font-medium transition', active ? 'bg-cocoa-700 text-cream-50' : 'bg-white text-cocoa-800 hover:bg-cream-200')}
              >
                {c.name}
              </button>
            )
          })}
        </div>
      </div>

      <ProductGrid
        filters={filters}
        emptyAction={() => {
          setSearch('')
          setParams({}, { replace: true })
        }}
      />
    </div>
  )
}

/** Page éditoriale « Nos tiramisus » : la spécialité mise en avant. */
export function TiramisusPage() {
  useSeo({ title: 'Nos tiramisus artisanaux', description: 'Le tiramisu, spécialité de MK Délices : classique, spéculoos, fruits rouges… en coupe individuelle ou à partager, livré à Dakar.', canonicalPath: '/nos-tiramisus' })

  return (
    <>
      <section className="relative overflow-hidden bg-cocoa-800 text-cream-50">
        <div className="absolute inset-0 opacity-30" style={{ background: 'radial-gradient(60% 80% at 80% 20%, #C08A4D 0%, transparent 60%), radial-gradient(50% 70% at 10% 90%, #B74264 0%, transparent 60%)' }} aria-hidden />
        <div className="container-page relative py-16 sm:py-24">
          <p className="text-xs font-semibold tracking-[0.22em] text-caramel-300 uppercase">Notre spécialité</p>
          <h1 className="mt-3 max-w-2xl text-4xl leading-tight font-semibold sm:text-6xl">Le tiramisu, à notre façon</h1>
          <p className="mt-5 max-w-xl text-lg text-cream-200">Biscuits imbibés, crème mascarpone onctueuse, cacao généreux. Chaque coupe est montée à la main, le jour même, pour une fraîcheur irréprochable.</p>
          <ul className="mt-8 grid max-w-2xl gap-3 text-sm sm:grid-cols-3">
            {[
              [<Snowflake key="s" className="size-4" />, 'Servi bien frais'],
              [<Clock key="c" className="size-4" />, 'Préparé sur commande'],
              [<Truck key="t" className="size-4" />, 'Livré à Dakar'],
            ].map(([icon, label]) => (
              <li key={label as string} className="flex items-center gap-2 rounded-full bg-white/10 px-4 py-2.5">
                {icon} {label}
              </li>
            ))}
          </ul>
        </div>
      </section>
      <div className="container-page py-10">
        <ProductGrid filters={{ category: 'tiramisus' }} />
        <div className="mt-12 text-center">
          <ButtonLink to="/boutique" variant="secondary">
            Voir aussi nos gâteaux et douceurs
          </ButtonLink>
        </div>
      </div>
    </>
  )
}

/** UX-03 — Fiche produit : nom, visuel, prix, format, description, allergènes, disponibilité, délai, CTA. */
export function ProductPage() {
  const { slug = '' } = useParams()
  const { add, isMutating } = useCart()
  const { data: config } = usePublicConfig()
  const { data: product, isLoading, error, refetch } = useQuery({
    queryKey: ['product', slug],
    queryFn: async () => (await api.get<{ data: Product }>(`/catalog/products/${slug}`)).data,
  })
  const variants = useMemo(() => product?.variants ?? [], [product])
  // Sélection propre au produit affiché : réinitialisée sans effet quand on change de fiche.
  const [selection, setSelection] = useState<{ productId?: string; variantId: string | null; qty: number }>({ variantId: null, qty: 1 })
  const current = selection.productId === product?.id ? selection : { productId: product?.id, variantId: variants.find((v) => v.available)?.id ?? null, qty: 1 }
  const { variantId, qty } = current
  const setVariantId = (id: string) => setSelection({ ...current, variantId: id, qty: 1 })
  const setQty = (value: number) => setSelection({ ...current, qty: value })
  const variant = variants.find((v) => v.id === variantId) ?? null

  const jsonLd = useMemo(
    () =>
      product
        ? {
            '@context': 'https://schema.org',
            '@type': 'Product',
            name: product.name,
            description: product.seo_description ?? product.tagline ?? undefined,
            image: product.images?.map((i) => i.url),
            category: product.category?.name,
            brand: { '@type': 'Brand', name: 'MK Délices' },
            offers: variants.map((v) => ({ '@type': 'Offer', sku: v.sku, name: v.label, price: v.price_xof, priceCurrency: 'XOF', availability: v.available ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' })),
            ...(product.rating?.count ? { aggregateRating: { '@type': 'AggregateRating', ratingValue: product.rating.average, reviewCount: product.rating.count } } : {}),
          }
        : null,
    [product, variants],
  )
  useSeo({
    title: product?.seo_title || product?.name || 'Produit',
    description: product?.seo_description ?? product?.tagline,
    canonicalPath: `/produits/${slug}`,
    image: product?.images?.[0]?.url,
    jsonLd,
  })

  if (isLoading) {
    return (
      <div className="container-page grid gap-8 py-10 lg:grid-cols-2">
        <div className="skeleton aspect-square rounded-[var(--radius-card)]" />
        <div className="space-y-4">
          <div className="skeleton h-10 w-2/3" />
          <div className="skeleton h-5 w-1/3" />
          <div className="skeleton h-32" />
        </div>
      </div>
    )
  }
  if (error || !product) {
    return (
      <div className="container-page py-16">
        <ErrorState error={error} onRetry={() => refetch()} />
      </div>
    )
  }

  const wa = whatsappLink(config?.settings['business.whatsapp_number'] as string | null, `Bonjour MK Délices, une question sur « ${product.name} » :`)
  const maxQty = Math.min(variant?.max_per_order ?? 20, variant?.stock_qty ?? 20, 20)

  const submit = async () => {
    if (!variant) return
    try {
      await add(variant.id, qty, `${product.name} (${variant.label})`)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <div className="container-page py-6 sm:py-10">
      <nav aria-label="Fil d'Ariane" className="mb-6 flex items-center gap-1 text-sm text-cocoa-500">
        <Link to="/boutique" className="hover:underline">
          Boutique
        </Link>
        <ChevronRight className="size-4" aria-hidden />
        {product.category && (
          <>
            <Link to={`/boutique?categorie=${product.category.slug}`} className="hover:underline">
              {product.category.name}
            </Link>
            <ChevronRight className="size-4" aria-hidden />
          </>
        )}
        <span className="truncate text-cocoa-800" aria-current="page">
          {product.name}
        </span>
      </nav>

      <div className="grid gap-8 lg:grid-cols-2 lg:gap-14">
        <div className="min-w-0 space-y-3 lg:sticky lg:top-24 lg:self-start">
          <ProductVisual product={product} priority className="aspect-square rounded-[var(--radius-card)] shadow-soft" sizes="(min-width: 1024px) 50vw, 100vw" />
          {(product.images?.length ?? 0) > 1 && (
            <div className="grid grid-cols-4 gap-2">
              {product.images!.slice(1, 5).map((img) => (
                <img key={img.id} src={img.url} alt={img.alt} loading="lazy" className="aspect-square rounded-[var(--radius-control)] object-cover" />
              ))}
            </div>
          )}
        </div>

        <div className="min-w-0 space-y-6">
          <div>
            <div className="mb-3 flex flex-wrap gap-2">
              {product.is_signature && <Badge tone="cocoa">Signature MK</Badge>}
              {product.is_new && <Badge tone="raspberry">Nouveau</Badge>}
              {product.requires_cold_chain && (
                <Badge tone="neutral">
                  <Snowflake className="size-3" aria-hidden /> Produit frais
                </Badge>
              )}
              {product.is_demo && <Badge tone="warning">Démonstration — prix fictif</Badge>}
            </div>
            <h1 className="text-4xl leading-tight font-semibold sm:text-5xl">{product.name}</h1>
            {product.tagline && <p className="mt-2 text-lg text-cocoa-500">{product.tagline}</p>}
            {product.rating && product.rating.count > 0 && (
              <p className="mt-2 flex items-center gap-1 text-sm text-cocoa-700">
                <Star className="size-4 fill-caramel-500 text-caramel-500" aria-hidden /> {product.rating.average} / 5 · {product.rating.count} avis
              </p>
            )}
            <p className="mt-4 text-3xl text-cocoa-900">
              <Price amount={variant?.price_xof ?? product.price_from_xof} compareAt={variant?.compare_at_price_xof} />
            </p>
          </div>

          {variants.length > 0 && <VariantPicker variants={variants} value={variantId} onChange={setVariantId} />}

          {product.description && <p className="leading-relaxed text-cocoa-700">{product.description}</p>}

          <AllergyNotice product={product} />

          <div className="sticky bottom-0 z-20 -mx-4 border-t border-cocoa-100/70 bg-cream-100/95 px-4 py-4 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0">
            {variant ? (
              <div className="flex items-center gap-3">
                <QuantityStepper label="Quantité" value={qty} onChange={setQty} max={Math.max(1, maxQty)} />
                <Button size="lg" className="min-w-0 flex-1" onClick={submit} loading={isMutating}>
                  Ajouter · <Price amount={variant.price_xof * qty} />
                </Button>
              </div>
            ) : (
              <p className="rounded-[var(--radius-control)] bg-cream-200 px-4 py-3 text-center font-medium text-cocoa-700">Actuellement indisponible</p>
            )}
            {variant && (
              <p className="mt-2 flex items-center gap-1.5 text-xs text-cocoa-500">
                <Clock className="size-3.5" aria-hidden /> {prepLabel(variant.prep_minutes)} · le créneau de livraison se choisit à la commande
              </p>
            )}
          </div>

          <ul className="grid gap-3 text-sm text-cocoa-700 sm:grid-cols-2">
            <li className="flex items-center gap-2">
              <ShieldCheck className="size-4 text-success-600" aria-hidden /> Paiement confirmé par le prestataire
            </li>
            <li className="flex items-center gap-2">
              <Truck className="size-4 text-cocoa-500" aria-hidden /> Livraison à domicile à Dakar
            </li>
          </ul>
          {wa && (
            <a href={wa} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 text-sm font-medium text-[#1f7a4d] hover:underline">
              <MessageCircle className="size-4" aria-hidden /> Une question ? Écrivez-nous sur WhatsApp
            </a>
          )}
        </div>
      </div>

      {product.rating && product.rating.items.length > 0 && (
        <section className="mt-16" aria-labelledby="avis">
          <h2 id="avis" className="text-2xl font-semibold">
            Avis clients
          </h2>
          <ul className="mt-5 grid gap-4 md:grid-cols-2">
            {product.rating.items.map((r, i) => (
              <li key={i} className="rounded-[var(--radius-card)] bg-white p-5 shadow-soft">
                <p className="text-caramel-500" aria-label={`${r.stars} étoiles sur 5`}>
                  {'★'.repeat(r.stars)}
                  <span className="text-cocoa-100">{'★'.repeat(5 - r.stars)}</span>
                </p>
                {r.comment && <p className="mt-2 text-cocoa-700">{r.comment}</p>}
                <p className="mt-2 text-sm text-cocoa-500">— {r.author}</p>
              </li>
            ))}
          </ul>
        </section>
      )}

      {product.related && product.related.length > 0 && (
        <section className="mt-16" aria-labelledby="associes">
          <h2 id="associes" className="text-2xl font-semibold">
            Vous aimerez aussi
          </h2>
          <ul className="mt-5 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
            {product.related.map((p) => (
              <li key={p.id}>
                <ProductCard product={p} />
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}
