import { Link } from 'react-router'
import { cn } from '@/lib/cn'
import type { Product } from '@/types/api'

/** Logo provisoire (monogramme + logotype) — à remplacer par l'identité officielle (D-BRAND-01). */
export function Logo({ className, tone = 'dark' }: { className?: string; tone?: 'dark' | 'light' }) {
  return (
    <Link to="/" className={cn('group inline-flex items-center gap-2.5', className)} aria-label="MK Délices — accueil">
      <span className={cn('grid size-10 place-items-center rounded-xl shadow-soft transition group-hover:rotate-[-4deg]', tone === 'dark' ? 'bg-cocoa-700' : 'bg-cream-50')}>
        <svg viewBox="0 0 64 64" className="size-7" aria-hidden>
          <path d="M14 44V22l9 13 9-13v22" fill="none" stroke={tone === 'dark' ? '#FFF9F2' : '#5B342B'} strokeWidth="4.5" strokeLinecap="round" strokeLinejoin="round" />
          <path d="M38 22v22M38 34l11-12M41 31l9 13" fill="none" stroke="#C08A4D" strokeWidth="4.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </span>
      <span className="leading-none">
        <span className={cn('block font-display text-xl font-semibold tracking-tight', tone === 'dark' ? 'text-cocoa-900' : 'text-cream-50')}>MK Délices</span>
        <span className={cn('block text-[10px] font-semibold tracking-[0.22em] uppercase', tone === 'dark' ? 'text-caramel-600' : 'text-caramel-300')}>Pâtisserie · Dakar</span>
      </span>
    </Link>
  )
}

const PALETTES = [
  { bg: '#F8ECDC', cream: '#FFF6E8', layer: '#C08A4D', top: '#5B342B', fruit: '#B74264' },
  { bg: '#F8E3E4', cream: '#FFF4F1', layer: '#9C6B4F', top: '#7A4D40', fruit: '#B74264' },
  { bg: '#EFE3D3', cream: '#FFFAF2', layer: '#A8733A', top: '#46271F', fruit: '#E4BF8F' },
  { bg: '#F6DBE2', cream: '#FFF8F6', layer: '#C08A4D', top: '#9C3553', fruit: '#5B342B' },
]

function hash(s: string): number {
  let h = 0
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0
  return Math.abs(h)
}

/**
 * Illustration vectorielle d'un dessert en verrine, utilisée tant que MK Délices n'a pas fourni
 * ses photographies : elle n'est jamais présentée comme une photo du produit réel.
 */
export function DessertArt({ seed, kind = 'tiramisu', className, label = true }: { seed: string; kind?: string; className?: string; label?: boolean }) {
  const p = PALETTES[hash(seed) % PALETTES.length]
  const isCake = kind === 'gateaux'
  const isSweet = kind === 'douceurs'

  return (
    <div className={cn('relative overflow-hidden', className)} style={{ background: `radial-gradient(120% 90% at 30% 20%, #fff 0%, ${p.bg} 55%, ${p.bg} 100%)` }}>
      <svg viewBox="0 0 200 200" className="absolute inset-0 m-auto h-[82%] w-[82%]" aria-hidden>
        <ellipse cx="100" cy="176" rx="64" ry="9" fill="#2A211F" opacity="0.08" />
        {isSweet ? (
          <g>
            {[[64, 118], [100, 104], [136, 118], [82, 146], [118, 146]].map(([x, y], i) => (
              <g key={i}>
                <circle cx={x} cy={y} r="22" fill={i % 2 ? p.top : p.layer} />
                <circle cx={x - 7} cy={y - 8} r="5" fill="#fff" opacity="0.35" />
              </g>
            ))}
          </g>
        ) : isCake ? (
          <g>
            <rect x="38" y="92" width="124" height="74" rx="10" fill={p.top} />
            <rect x="38" y="118" width="124" height="10" fill={p.cream} opacity="0.9" />
            <rect x="38" y="142" width="124" height="8" fill={p.cream} opacity="0.75" />
            <path d="M38 98c10 10 20-6 31 4s21-6 31 4 21-6 31 4 21-6 31 4V92H38z" fill={p.cream} />
            {[60, 84, 108, 132].map((x) => (
              <circle key={x} cx={x + 6} cy="84" r="7" fill={p.fruit} />
            ))}
          </g>
        ) : (
          <g>
            <path d="M52 62h96l-8 104a10 10 0 0 1-10 9H70a10 10 0 0 1-10-9z" fill="#ffffff" opacity="0.55" stroke="#ffffff" strokeWidth="2" />
            <path d="M58 140h84l-2.4 25a8 8 0 0 1-8 7H68.4a8 8 0 0 1-8-7z" fill={p.layer} />
            <path d="M56 118h88l-1.9 22H57.9z" fill={p.cream} />
            <path d="M55 100h90l-1.6 18H56.6z" fill={p.layer} opacity="0.85" />
            <path d="M54 80h92l-1.7 20H55.7z" fill={p.cream} />
            <path d="M54 80c6-6 12 2 18-3s12 3 18-2 12 3 18-2 12 3 18-2 12 4 20 9z" fill={p.top} />
            {[70, 92, 112, 130].map((x, i) => (
              <circle key={x} cx={x} cy={76 - (i % 2) * 3} r="1.8" fill={p.cream} opacity="0.7" />
            ))}
            <circle cx="128" cy="70" r="8" fill={p.fruit} />
            <path d="M128 62c2-5 6-6 9-5" stroke="#22724F" strokeWidth="2.5" fill="none" strokeLinecap="round" />
          </g>
        )}
      </svg>
      {label && <span className="absolute right-2 bottom-2 rounded-full bg-white/80 px-2 py-0.5 text-[10px] font-medium text-cocoa-500 backdrop-blur">Illustration</span>}
    </div>
  )
}

/** Visuel produit : photo optimisée (srcset, lazy) ou illustration de remplacement. */
export function ProductVisual({ product, className, priority = false, sizes = '(min-width: 1024px) 33vw, 50vw' }: { product: Product; className?: string; priority?: boolean; sizes?: string }) {
  const image = product.images?.[0]
  if (!image) return <DessertArt seed={product.slug} kind={product.category?.slug} className={className} />

  return (
    <div className={cn('relative overflow-hidden bg-cream-200', className)}>
      <img
        src={image.url}
        srcSet={image.srcset ?? undefined}
        sizes={sizes}
        alt={image.alt}
        width={image.width ?? undefined}
        height={image.height ?? undefined}
        loading={priority ? 'eager' : 'lazy'}
        decoding="async"
        className="size-full object-cover"
      />
      {image.is_illustrative && <span className="absolute right-2 bottom-2 rounded-full bg-white/80 px-2 py-0.5 text-[10px] font-medium text-cocoa-500">Photo d'illustration</span>}
    </div>
  )
}
