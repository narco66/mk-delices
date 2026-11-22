import { AlertCircle, Clock, Snowflake, Sparkles } from 'lucide-react'
import { Link } from 'react-router'
import { ProductVisual } from '@/components/brand/Brand'
import { Badge, Price } from '@/components/ui/Display'
import { Notice } from '@/components/ui/Feedback'
import { FavoriteButton } from '@/features/account/favorites'
import { cn } from '@/lib/cn'
import { allergenLabel } from '@/lib/format'
import type { Product, Variant } from '@/types/api'

export function ProductCard({ product, priority = false }: { product: Product; priority?: boolean }) {
  const unavailable = product.available === false

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-[var(--radius-card)] bg-white shadow-soft transition duration-300 hover:-translate-y-1 hover:shadow-lift">
      <Link to={`/produits/${product.slug}`} className="absolute inset-0 z-10" aria-label={`${product.name} — voir la fiche`} />
      <div className="relative">
        <ProductVisual product={product} priority={priority} className="aspect-[4/5] transition duration-500 group-hover:scale-[1.03]" />
        <div className="absolute top-3 left-3 flex flex-wrap gap-1.5">
          {product.is_signature && (
            <Badge tone="cocoa">
              <Sparkles className="size-3" aria-hidden /> Signature
            </Badge>
          )}
          {product.is_new && <Badge tone="raspberry">Nouveau</Badge>}
          {unavailable && <Badge tone="neutral">Indisponible</Badge>}
        </div>
        <FavoriteButton product={product} className="absolute top-3 right-3 z-20" />
      </div>
      <div className="flex flex-1 flex-col gap-1.5 p-4">
        <p className="text-xs font-medium tracking-wide text-caramel-600 uppercase">{product.category?.name}</p>
        <h3 className="font-display text-lg leading-snug font-semibold text-cocoa-900">{product.name}</h3>
        {product.tagline && <p className="line-clamp-2 text-sm text-cocoa-500">{product.tagline}</p>}
        <div className="mt-auto flex items-end justify-between pt-3">
          <p className="text-sm text-cocoa-500">
            {product.variants && product.variants.length > 1 ? 'dès ' : ''}
            <Price amount={product.price_from_xof} className="text-base text-cocoa-900" />
          </p>
          {product.allergens.length > 0 && (
            <span className="text-[11px] text-cocoa-500" title={`Allergènes : ${product.allergens.map(allergenLabel).join(', ')}`}>
              {product.allergens.length} allergène{product.allergens.length > 1 ? 's' : ''}
            </span>
          )}
        </div>
      </div>
    </article>
  )
}

export function ProductCardSkeleton() {
  return (
    <div className="overflow-hidden rounded-[var(--radius-card)] bg-white shadow-soft">
      <div className="skeleton aspect-[4/5] rounded-none" />
      <div className="space-y-2 p-4">
        <div className="skeleton h-3 w-20" />
        <div className="skeleton h-5 w-3/4" />
        <div className="skeleton h-4 w-1/3" />
      </div>
    </div>
  )
}

/** Allergènes et conservation visibles avant ajout au panier (CAT-001, BR §7). */
export function AllergyNotice({ product }: { product: Product }) {
  return (
    <section aria-labelledby="allergenes" className="rounded-[var(--radius-card)] border border-caramel-500/30 bg-warning-50/60 p-4">
      <h2 id="allergenes" className="flex items-center gap-2 text-sm font-semibold text-cocoa-900">
        <AlertCircle className="size-4 text-caramel-600" aria-hidden /> Allergènes
      </h2>
      {product.allergens.length ? (
        <ul className="mt-2 flex flex-wrap gap-1.5">
          {product.allergens.map((a) => (
            <li key={a}>
              <Badge tone="caramel">{allergenLabel(a)}</Badge>
            </li>
          ))}
        </ul>
      ) : (
        <p className="mt-1 text-sm text-cocoa-500">Aucun allergène majeur déclaré. Contactez-nous en cas de doute.</p>
      )}
      {product.ingredients && (
        <p className="mt-3 text-sm text-cocoa-700">
          <span className="font-medium">Ingrédients : </span>
          {product.ingredients}
        </p>
      )}
      {product.storage_instructions && (
        <p className="mt-2 flex gap-2 text-sm text-cocoa-700">
          <Snowflake className="mt-0.5 size-4 shrink-0 text-cocoa-500" aria-hidden />
          <span>
            <span className="font-medium">Conservation : </span>
            {product.storage_instructions}
          </span>
        </p>
      )}
    </section>
  )
}

export function VariantPicker({ variants, value, onChange }: { variants: Variant[]; value: string | null; onChange: (id: string) => void }) {
  return (
    <fieldset>
      <legend className="mb-2 text-sm font-semibold text-cocoa-900">Format</legend>
      <div className="grid gap-2">
        {variants.map((v) => {
          const selected = v.id === value
          return (
            <label
              key={v.id}
              className={cn(
                'has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-raspberry-500 flex min-h-14 cursor-pointer items-center justify-between gap-3 rounded-[var(--radius-control)] border-2 bg-white px-4 py-3 transition',
                selected ? 'border-cocoa-700 shadow-soft' : 'border-cocoa-300 hover:border-cocoa-500',
                !v.available && 'cursor-not-allowed opacity-50',
              )}
            >
              <input type="radio" name="variant" value={v.id} checked={selected} disabled={!v.available} onChange={() => onChange(v.id)} className="sr-only" />
              <span className="min-w-0">
                <span className="block font-medium">{v.label}</span>
                <span className="flex flex-wrap items-center gap-1 text-xs text-cocoa-500">
                  {v.serves ? `${v.serves} pers. · ` : ''}
                  {!v.available ? (
                    'Indisponible'
                  ) : (
                    <>
                      <Clock className="size-3" aria-hidden /> {prepLabel(v.prep_minutes)}
                    </>
                  )}
                </span>
              </span>
              <Price amount={v.price_xof} compareAt={v.compare_at_price_xof} />
            </label>
          )
        })}
      </div>
    </fieldset>
  )
}

export function prepLabel(minutes: number): string {
  if (!minutes) return 'Disponible rapidement'
  if (minutes < 60) return `Préparation ${minutes} min`
  const hours = Math.round(minutes / 60)
  return hours >= 24 ? `Commander ${Math.round(hours / 24)} j à l'avance` : `Préparation ${hours} h`
}

export function DemoCatalogNotice({ show }: { show: boolean }) {
  if (!show) return null
  return (
    <Notice tone="warning" title="Catalogue de démonstration">
      Produits, prix et visuels fictifs en attente du catalogue officiel de MK Délices.
    </Notice>
  )
}
