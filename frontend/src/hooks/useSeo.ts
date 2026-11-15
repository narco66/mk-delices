import { useEffect } from 'react'

interface SeoOptions {
  title: string
  description?: string | null
  canonicalPath?: string
  image?: string | null
  jsonLd?: Record<string, unknown> | null
  noindex?: boolean
}

const SITE = (import.meta.env.VITE_SITE_URL as string | undefined)?.replace(/\/$/, '') ?? window.location.origin

function upsertMeta(attr: 'name' | 'property', key: string, content: string | null | undefined) {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
  if (!content) {
    el?.remove()
    return
  }
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(attr, key)
    document.head.appendChild(el)
  }
  el.content = content
}

/**
 * Métadonnées SEO côté client (titre, description, canonique, Open Graph, JSON-LD).
 * En production, le backend injecte aussi ces balises dans le HTML initial des pages
 * catalogue (voir SpaController) pour les robots qui n'exécutent pas JavaScript.
 */
export function useSeo({ title, description, canonicalPath, image, jsonLd, noindex }: SeoOptions) {
  useEffect(() => {
    const fullTitle = title.includes('MK Délices') ? title : `${title} · MK Délices`
    document.title = fullTitle
    upsertMeta('name', 'description', description ?? undefined)
    upsertMeta('property', 'og:title', fullTitle)
    upsertMeta('property', 'og:description', description ?? undefined)
    upsertMeta('property', 'og:image', image ?? undefined)
    upsertMeta('name', 'robots', noindex ? 'noindex, nofollow' : undefined)

    let link = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]')
    if (canonicalPath) {
      if (!link) {
        link = document.createElement('link')
        link.rel = 'canonical'
        document.head.appendChild(link)
      }
      link.href = SITE + canonicalPath
      upsertMeta('property', 'og:url', SITE + canonicalPath)
    } else {
      link?.remove()
    }

    const id = 'mk-jsonld'
    document.getElementById(id)?.remove()
    if (jsonLd) {
      const script = document.createElement('script')
      script.type = 'application/ld+json'
      script.id = id
      script.textContent = JSON.stringify(jsonLd)
      document.head.appendChild(script)
    }
  }, [title, description, canonicalPath, image, jsonLd, noindex])
}
