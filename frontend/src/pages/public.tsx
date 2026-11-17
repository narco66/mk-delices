import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Hand, Mail, MapPin, MessageCircle, Phone, ShieldCheck, Snowflake, Tag, Truck } from 'lucide-react'
import type { ReactNode } from 'react'
import Markdown from 'react-markdown'
import { Link, useLocation } from 'react-router'
import { DessertArt } from '@/components/brand/Brand'
import { buttonClass, ButtonLink } from '@/components/ui/Button'
import { Badge, Card, Price } from '@/components/ui/Display'
import { EmptyState, ErrorState, Notice, Skeleton } from '@/components/ui/Feedback'
import { DemoCatalogNotice, ProductCard, ProductCardSkeleton } from '@/features/catalog/components'
import { useHomeContent, usePublicConfig, useZones, whatsappLink } from '@/hooks/usePublicData'
import { useSeo } from '@/hooks/useSeo'
import { api, toQuery } from '@/lib/api'
import { formatDateTime, formatXof } from '@/lib/format'
import type { Paginated, Product } from '@/types/api'

interface ContentPage {
  slug: string
  title: string
  excerpt: string | null
  body: string
  seo_title: string | null
  seo_description: string | null
  requires_validation: boolean
  updated_at: string
}

type Items<T> = { items?: T[]; notice?: string }

function useProducts(params: Record<string, string | number | boolean>, key: string) {
  return useQuery({
    queryKey: ['products', key],
    queryFn: async () => (await api.get<Paginated<Product>>(`/catalog/products${toQuery(params)}`)).data,
  })
}

function usePage(slug: string) {
  return useQuery({ queryKey: ['page', slug], queryFn: async () => (await api.get<{ data: ContentPage }>(`/content/pages/${slug}`)).data, retry: false })
}

const ICONS: Record<string, ReactNode> = {
  hand: <Hand className="size-6" />,
  snowflake: <Snowflake className="size-6" />,
  truck: <Truck className="size-6" />,
  shield: <ShieldCheck className="size-6" />,
}

function SectionTitle({ eyebrow, title, action }: { eyebrow: string; title: string; action?: ReactNode }) {
  return (
    <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
      <div>
        <p className="text-xs font-semibold tracking-[0.2em] text-raspberry-500 uppercase">{eyebrow}</p>
        <h2 className="mt-2 text-3xl font-semibold sm:text-4xl">{title}</h2>
      </div>
      {action}
    </div>
  )
}

function ProductRow({ params, cacheKey }: { params: Record<string, string | number | boolean>; cacheKey: string }) {
  const { data, isLoading, error, refetch } = useProducts({ per_page: 4, ...params }, cacheKey)
  if (error) return <ErrorState error={error} onRetry={() => refetch()} />
  if (!isLoading && !data?.length) return <EmptyState title="Bientôt disponible" description="Nos créations arrivent très vite." />
  return (
    <ul className="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
      {isLoading
        ? Array.from({ length: 4 }, (_, i) => (
            <li key={i}>
              <ProductCardSkeleton />
            </li>
          ))
        : data!.map((p, i) => (
            <li key={p.id}>
              <ProductCard product={p} priority={i < 2} />
            </li>
          ))}
    </ul>
  )
}

/** UX-01 — Accueil : contenus éditables (CMS) et produits issus de la base. */
export function HomePage() {
  const { data: content, isLoading } = useHomeContent()
  const { data: config } = usePublicConfig()
  const { data: signature } = useProducts({ signature: true, per_page: 1 }, 'hero')
  const { data: zones } = useZones()
  useSeo({
    title: 'MK Délices — Tiramisus et pâtisseries artisanales à Dakar',
    description: 'Tiramisus, gâteaux et douceurs faits main à Dakar. Commandez en ligne ou par WhatsApp, livraison au créneau de votre choix.',
    canonicalPath: '/',
    jsonLd: { '@context': 'https://schema.org', '@type': 'Bakery', name: 'MK Délices', address: { '@type': 'PostalAddress', addressLocality: 'Dakar', addressCountry: 'SN' }, servesCuisine: 'Pâtisserie', currenciesAccepted: 'XOF' },
  })

  const hero = (content?.['home.hero'] ?? {}) as Record<string, string | null>
  const args = (content?.['home.arguments'] ?? {}) as Items<{ icon: string; title: string; text: string }>
  const steps = (content?.['home.steps'] ?? {}) as Items<{ title: string; text: string }>
  const testimonials = (content?.['home.testimonials'] ?? {}) as Items<{ author: string; text: string; stars: number }>
  const wa = whatsappLink(config?.settings['business.whatsapp_number'] as string | null, 'Bonjour MK Délices, je souhaite passer commande.')
  const star = signature?.[0]

  return (
    <>
      {/* Hero */}
      <section className="relative overflow-hidden">
        <div className="absolute inset-0 -z-10" style={{ background: 'radial-gradient(70% 60% at 85% 10%, #F6DBE2 0%, transparent 60%), radial-gradient(60% 60% at 0% 100%, #F0DCC3 0%, transparent 60%)' }} aria-hidden />
        <div className="container-page grid items-center gap-10 py-12 sm:py-16 lg:grid-cols-[1.05fr_1fr] lg:py-24">
          <div className="animate-fade-up">
            {isLoading ? (
              <div className="space-y-4">
                <Skeleton className="h-4 w-48" />
                <Skeleton className="h-16 w-full" />
                <Skeleton className="h-6 w-4/5" />
              </div>
            ) : (
              <>
                <p className="inline-flex items-center gap-2 rounded-full bg-white/70 px-3 py-1 text-xs font-semibold tracking-[0.16em] text-caramel-600 uppercase shadow-soft">{hero.eyebrow ?? 'Pâtisserie artisanale · Dakar'}</p>
                <h1 className="mt-5 text-[2.6rem] leading-[1.05] font-semibold text-balance sm:text-6xl lg:text-7xl">{hero.title ?? 'Le tiramisu qui fait fondre Dakar'}</h1>
                <p className="mt-5 max-w-xl text-lg text-cocoa-700">{hero.subtitle}</p>
              </>
            )}
            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
              <ButtonLink to={hero.cta_href ?? '/nos-tiramisus'} size="lg" icon={<ArrowRight className="size-5" />}>
                {hero.cta_label ?? 'Découvrir nos tiramisus'}
              </ButtonLink>
              {wa && (
                <a href={wa} target="_blank" rel="noopener noreferrer" className={buttonClass('secondary', 'lg')}>
                  <MessageCircle className="size-5 text-[#1f7a4d]" /> Commander sur WhatsApp
                </a>
              )}
            </div>
            <ul className="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm text-cocoa-700">
              <li className="flex items-center gap-2"><Hand className="size-4 text-caramel-600" aria-hidden /> Fait main</li>
              <li className="flex items-center gap-2"><Snowflake className="size-4 text-caramel-600" aria-hidden /> Livré bien frais</li>
              <li className="flex items-center gap-2"><Truck className="size-4 text-caramel-600" aria-hidden /> Partout à Dakar*</li>
            </ul>
          </div>
          <div className="relative mx-auto w-full max-w-md lg:max-w-none">
            <div className="absolute -inset-6 -z-10 rotate-6 rounded-[48px] bg-raspberry-100/60" aria-hidden />
            {hero.image ? (
              <img src={hero.image} alt="Tiramisu MK Délices" className="aspect-[4/5] w-full rounded-[40px] object-cover shadow-lift" />
            ) : (
              <DessertArt seed="hero-tiramisu" className="aspect-[4/5] w-full rounded-[40px] shadow-lift" />
            )}
            {star && (
              <Link to={`/produits/${star.slug}`} className="absolute -bottom-5 left-4 flex items-center gap-3 rounded-2xl bg-white/95 px-4 py-3 shadow-lift backdrop-blur transition hover:-translate-y-0.5 sm:-left-6">
                <span className="grid size-11 place-items-center rounded-xl bg-cocoa-700 font-display text-lg text-cream-50">★</span>
                <span>
                  <span className="block text-xs text-cocoa-500">Notre signature</span>
                  <span className="block font-semibold">{star.name}</span>
                  <span className="text-sm text-cocoa-700">dès {formatXof(star.price_from_xof)}</span>
                </span>
              </Link>
            )}
          </div>
        </div>
      </section>

      {/* Tiramisus */}
      <section className="container-page py-14">
        <SectionTitle eyebrow="La spécialité de la maison" title="Nos tiramisus" action={<ButtonLink to="/nos-tiramisus" variant="ghost" icon={<ArrowRight className="size-4" />}>Tous les tiramisus</ButtonLink>} />
        <ProductRow params={{ category: 'tiramisus' }} cacheKey="home-tiramisus" />
      </section>

      {/* Arguments */}
      {args.items && args.items.length > 0 && (
        <section className="bg-cream-200/60 py-14">
          <ul className="container-page grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {args.items.map((a) => (
              <li key={a.title} className="rounded-[var(--radius-card)] bg-white/80 p-6 shadow-soft">
                <div className="mb-4 grid size-12 place-items-center rounded-2xl bg-cocoa-700 text-cream-50">{ICONS[a.icon] ?? <ShieldCheck className="size-6" />}</div>
                <h3 className="text-lg font-semibold">{a.title}</h3>
                <p className="mt-1 text-sm text-cocoa-500">{a.text}</p>
              </li>
            ))}
          </ul>
        </section>
      )}

      {/* Populaires & nouveautés */}
      <section className="container-page py-14">
        <SectionTitle eyebrow="Les préférés" title="Produits populaires" action={<ButtonLink to="/boutique" variant="ghost" icon={<ArrowRight className="size-4" />}>Toute la boutique</ButtonLink>} />
        <ProductRow params={{ featured: true }} cacheKey="home-featured" />
      </section>
      <section className="container-page pb-14">
        <SectionTitle eyebrow="Fraîchement sortis de l'atelier" title="Nouveautés" />
        <ProductRow params={{ new: true }} cacheKey="home-new" />
      </section>

      {/* Fonctionnement */}
      {steps.items && steps.items.length > 0 && (
        <section className="bg-cocoa-800 py-16 text-cream-50">
          <div className="container-page">
            <p className="text-xs font-semibold tracking-[0.2em] text-caramel-300 uppercase">Simple comme un dessert</p>
            <h2 className="mt-2 text-3xl font-semibold sm:text-4xl">Comment commander ?</h2>
            <ol className="mt-10 grid gap-6 md:grid-cols-3">
              {steps.items.map((s, i) => (
                <li key={s.title} className="relative rounded-[var(--radius-card)] bg-white/5 p-6 ring-1 ring-white/10">
                  <span className="font-display text-5xl text-caramel-300">{String(i + 1).padStart(2, '0')}</span>
                  <h3 className="mt-3 text-xl font-semibold">{s.title}</h3>
                  <p className="mt-1 text-cream-300">{s.text}</p>
                </li>
              ))}
            </ol>
          </div>
        </section>
      )}

      {/* Livraison */}
      <section className="container-page py-14">
        <div className="grid items-center gap-8 rounded-[32px] bg-white p-6 shadow-soft sm:p-10 lg:grid-cols-2">
          <div>
            <p className="text-xs font-semibold tracking-[0.2em] text-raspberry-500 uppercase">Livraison</p>
            <h2 className="mt-2 text-3xl font-semibold">Livré chez vous, au bon moment</h2>
            <p className="mt-3 text-cocoa-500">Choisissez votre zone et un créneau : nos livreurs partenaires vous remettent votre commande, conditionnée pour rester fraîche.</p>
            <ButtonLink to="/livraison" variant="secondary" className="mt-6" icon={<MapPin className="size-4" />}>
              Zones et tarifs
            </ButtonLink>
          </div>
          <ul className="grid gap-2 sm:grid-cols-2">
            {(zones ?? []).slice(0, 6).map((z) => (
              <li key={z.id} className="flex items-center justify-between rounded-[var(--radius-control)] bg-cream-100 px-4 py-3 text-sm">
                <span className="font-medium">{z.name}</span>
                <span className="text-cocoa-500">{formatXof(z.delivery_fee_xof)}</span>
              </li>
            ))}
            {zones && zones.length === 0 && <li className="text-sm text-cocoa-500">Zones de livraison en cours de définition.</li>}
          </ul>
        </div>
      </section>

      {/* Témoignages */}
      {testimonials.items && testimonials.items.length > 0 && (
        <section className="container-page pb-14">
          <SectionTitle eyebrow="Ils en parlent" title="Témoignages" />
          {testimonials.notice && <Notice tone="warning" className="mb-5">{testimonials.notice}</Notice>}
          <ul className="grid gap-5 md:grid-cols-2">
            {testimonials.items.map((t, i) => (
              <li key={i}>
                <figure className="h-full rounded-[var(--radius-card)] bg-white p-6 shadow-soft">
                  <p className="text-caramel-500" role="img" aria-label={`${t.stars} étoiles sur 5`}>{'★'.repeat(t.stars)}</p>
                  <blockquote className="mt-3 font-display text-xl leading-snug">« {t.text} »</blockquote>
                  <figcaption className="mt-3 text-sm text-cocoa-500">— {t.author}</figcaption>
                </figure>
              </li>
            ))}
          </ul>
        </section>
      )}

      {/* CTA */}
      <section className="container-page pb-6">
        <div className="relative overflow-hidden rounded-[32px] bg-raspberry-500 px-6 py-12 text-center text-white sm:px-12">
          <div className="absolute inset-0 opacity-25" style={{ background: 'radial-gradient(40% 80% at 10% 0%, #fff 0%, transparent 60%)' }} aria-hidden />
          <h2 className="relative text-3xl font-semibold sm:text-4xl">Une envie de douceur ?</h2>
          <p className="relative mx-auto mt-3 max-w-xl text-white/90">Commandez en quelques clics, sans créer de compte. Nous confirmons rapidement votre commande.</p>
          <div className="relative mt-7 flex flex-col justify-center gap-3 sm:flex-row">
            <ButtonLink to="/boutique" size="lg" className="bg-white text-raspberry-600 hover:bg-cream-50">
              Commander maintenant
            </ButtonLink>
            {wa && (
              <a href={wa} target="_blank" rel="noopener noreferrer" className={buttonClass('whatsapp', 'lg')}>
                <MessageCircle className="size-5" /> WhatsApp
              </a>
            )}
          </div>
        </div>
        <p className="mt-3 text-xs text-cocoa-500">* Selon les zones desservies affichées lors de la commande.</p>
      </section>
    </>
  )
}

function ContentPageView({ slug, fallbackTitle }: { slug: string; fallbackTitle: string }) {
  const { data: page, isLoading, error, refetch } = usePage(slug)
  useSeo({ title: page?.seo_title || page?.title || fallbackTitle, description: page?.seo_description ?? page?.excerpt, canonicalPath: `/${slug}` })

  return (
    <article className="container-page max-w-3xl py-12">
      {isLoading ? (
        <div className="space-y-4">
          <Skeleton className="h-12 w-2/3" />
          <Skeleton className="h-40" />
        </div>
      ) : error || !page ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : (
        <>
          <h1 className="text-4xl font-semibold sm:text-5xl">{page.title}</h1>
          {page.excerpt && <p className="mt-3 text-lg text-cocoa-500">{page.excerpt}</p>}
          {page.requires_validation && (
            <Notice tone="warning" className="mt-6">
              Contenu en attente de validation par MK Délices.
            </Notice>
          )}
          <div className="prose-mk mt-8">
            <Markdown>{page.body}</Markdown>
          </div>
          <p className="mt-10 text-xs text-cocoa-500">Dernière mise à jour : {formatDateTime(page.updated_at, { timeStyle: undefined })}</p>
        </>
      )}
    </article>
  )
}

export function StoryPage() {
  return <ContentPageView slug="notre-histoire" fallbackTitle="Notre histoire" />
}

export function LegalPage() {
  const slug = useLocation().pathname.replace(/^\//, '')
  return <ContentPageView slug={slug} fallbackTitle="Informations légales" />
}

export function DeliveryPage() {
  const { data: zones, isLoading, error, refetch } = useZones()
  return (
    <>
      <ContentPageView slug="livraison" fallbackTitle="Livraison" />
      <section className="container-page max-w-3xl pb-12" aria-labelledby="zones">
        <h2 id="zones" className="mb-4 text-2xl font-semibold">Zones desservies et frais</h2>
        {error ? (
          <ErrorState error={error} onRetry={() => refetch()} />
        ) : isLoading ? (
          <Skeleton className="h-40" />
        ) : !zones?.length ? (
          <EmptyState title="Zones en cours de définition" description="Contactez-nous pour connaître les possibilités de livraison." />
        ) : (
          <ul className="divide-y divide-cocoa-100/70 overflow-hidden rounded-[var(--radius-card)] bg-white shadow-soft">
            {zones.map((z) => (
              <li key={z.id} className="flex flex-col gap-1 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-semibold">{z.name}</p>
                  {z.districts && <p className="text-sm text-cocoa-500">{z.districts}</p>}
                </div>
                <div className="text-left sm:text-right">
                  <p className="font-semibold">{formatXof(z.delivery_fee_xof)}</p>
                  {z.min_order_xof ? <p className="text-xs text-cocoa-500">Minimum de commande {formatXof(z.min_order_xof)}</p> : null}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </>
  )
}

export function ContactPage() {
  const { data: config } = usePublicConfig()
  const s = config?.settings ?? {}
  const wa = whatsappLink(s['business.whatsapp_number'] as string | null, 'Bonjour MK Délices,')
  const phone = s['business.phone'] as string | null
  const email = s['business.email'] as string | null

  return (
    <>
      <ContentPageView slug="contact" fallbackTitle="Contact" />
      <section className="container-page grid max-w-3xl gap-4 pb-12 sm:grid-cols-3">
        <ContactCard icon={<MessageCircle className="size-5" />} title="WhatsApp" value={wa ? 'Écrire un message' : 'Numéro à venir'} href={wa ?? undefined} />
        <ContactCard icon={<Phone className="size-5" />} title="Téléphone" value={phone ?? 'À venir'} href={phone ? `tel:${phone.replace(/\s/g, '')}` : undefined} />
        <ContactCard icon={<Mail className="size-5" />} title="Email" value={email ?? 'À venir'} href={email ? `mailto:${email}` : undefined} />
      </section>
    </>
  )
}

function ContactCard({ icon, title, value, href }: { icon: ReactNode; title: string; value: string; href?: string }) {
  const inner = (
    <Card className="h-full p-5 transition hover:shadow-lift">
      <div className="mb-3 grid size-10 place-items-center rounded-xl bg-cocoa-700 text-cream-50">{icon}</div>
      <p className="text-sm text-cocoa-500">{title}</p>
      <p className="font-semibold">{value}</p>
    </Card>
  )
  return href ? (
    <a href={href} target={href.startsWith('http') ? '_blank' : undefined} rel="noopener noreferrer">
      {inner}
    </a>
  ) : (
    inner
  )
}

export function FaqPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['content', 'home'],
    queryFn: async () => (await api.get<{ data: Record<string, Record<string, unknown>> }>('/content/home')).data,
  })
  const items = ((data?.['faq.items'] as Items<{ q: string; a: string }>) ?? {}).items ?? []
  useSeo({
    title: 'Questions fréquentes',
    description: 'Commande, paiement, livraison, conservation et allergènes : toutes les réponses de MK Délices.',
    canonicalPath: '/faq',
    jsonLd: items.length ? { '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: items.map((i) => ({ '@type': 'Question', name: i.q, acceptedAnswer: { '@type': 'Answer', text: i.a } })) } : null,
  })

  return (
    <div className="container-page max-w-3xl py-12">
      <h1 className="text-4xl font-semibold sm:text-5xl">Questions fréquentes</h1>
      <div className="mt-8 space-y-3">
        {isLoading && <Skeleton className="h-48" />}
        {items.map((item) => (
          <details key={item.q} className="group rounded-[var(--radius-card)] bg-white p-5 shadow-soft open:shadow-lift">
            <summary className="flex min-h-8 cursor-pointer list-none items-center justify-between gap-4 font-semibold">
              {item.q}
              <span className="text-xl text-caramel-600 transition group-open:rotate-45" aria-hidden>+</span>
            </summary>
            <p className="mt-3 text-cocoa-700">{item.a}</p>
          </details>
        ))}
      </div>
    </div>
  )
}

interface PublicPromotion {
  code: string
  name: string
  description: string | null
  discount_type: 'percent' | 'fixed'
  discount_value: number
  min_subtotal_xof: number | null
  ends_at: string | null
}

export function PromotionsPage() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['promotions'],
    queryFn: async () => (await api.get<{ data: PublicPromotion[] }>('/promotions')).data,
  })
  const { data: products } = useProducts({ featured: true, per_page: 4 }, 'promo-featured')
  useSeo({ title: 'Promotions', description: 'Les offres du moment chez MK Délices.', canonicalPath: '/promotions' })

  return (
    <div className="container-page py-12">
      <h1 className="text-4xl font-semibold sm:text-5xl">Promotions</h1>
      <p className="mt-3 text-cocoa-500">Un seul code promotionnel par commande, à saisir dans votre panier.</p>
      <div className="mt-8">
        {error ? (
          <ErrorState error={error} onRetry={() => refetch()} />
        ) : isLoading ? (
          <Skeleton className="h-32" />
        ) : !data?.length ? (
          <EmptyState icon={<Tag className="size-6" />} title="Aucune promotion en cours" description="Revenez bientôt ou suivez-nous sur les réseaux sociaux." />
        ) : (
          <ul className="grid gap-4 md:grid-cols-2">
            {data.map((p) => (
              <li key={p.code} className="relative overflow-hidden rounded-[var(--radius-card)] bg-white p-6 shadow-soft">
                <div className="absolute top-0 right-0 h-full w-2 bg-raspberry-500" aria-hidden />
                <p className="font-display text-3xl font-semibold text-raspberry-600">{p.discount_type === 'percent' ? `−${p.discount_value} %` : `−${formatXof(p.discount_value)}`}</p>
                <h2 className="mt-1 text-lg font-semibold">{p.name}</h2>
                {p.description && <p className="text-sm text-cocoa-500">{p.description}</p>}
                <p className="mt-4 flex flex-wrap items-center gap-2 text-sm">
                  Code : <Badge tone="cocoa">{p.code}</Badge>
                  {p.min_subtotal_xof ? <span className="text-cocoa-500">dès <Price amount={p.min_subtotal_xof} /></span> : null}
                  {p.ends_at && <span className="text-cocoa-500">jusqu'au {formatDateTime(p.ends_at, { timeStyle: undefined })}</span>}
                </p>
              </li>
            ))}
          </ul>
        )}
      </div>
      {products && products.length > 0 && (
        <section className="mt-14">
          <h2 className="mb-5 text-2xl font-semibold">À savourer avec votre code</h2>
          <DemoCatalogNotice show={products.some((p) => p.is_demo)} />
          <ul className="mt-5 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
            {products.map((p) => (
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

export function NotFoundPage() {
  useSeo({ title: 'Page introuvable', noindex: true })
  return (
    <div className="container-page grid place-items-center py-24 text-center">
      <DessertArt seed="404" className="mb-8 size-40 rounded-full" label={false} />
      <h1 className="text-4xl font-semibold">Cette page s'est évaporée</h1>
      <p className="mt-2 text-cocoa-500">Comme un tiramisu un dimanche après-midi.</p>
      <ButtonLink to="/" className="mt-6">
        Retour à l'accueil
      </ButtonLink>
    </div>
  )
}
