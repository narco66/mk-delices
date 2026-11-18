import { useQuery } from '@tanstack/react-query'
import { Check, Crosshair, Loader2, LocateFixed, MapPin, Search, X } from 'lucide-react'
import { lazy, Suspense, useEffect, useMemo, useState } from 'react'
import { Button } from '@/components/ui/Button'
import { Notice, Skeleton } from '@/components/ui/Feedback'
import { Input } from '@/components/ui/Form'
import { usePublicConfig, whatsappLink } from '@/hooks/usePublicData'
import { formatXof } from '@/lib/format'
import type { GeoArea, GeoPlace, GeoResolution, PickedLocation } from '@/types/api'
import {
  ACCURACY_WARNING_M,
  GEOLOCATE_MESSAGES,
  geolocate,
  type GeolocateError,
  resolveLocation,
  round6,
  searchPlaces,
} from './geo'

const PinMap = lazy(() => import('./LeafletMaps').then((m) => ({ default: m.PinMap })))

/**
 * Position de livraison (GEO-LOC) : GPS sur demande, recherche, repère déplaçable, confirmation explicite.
 * Jamais obligatoire : sans position, la saisie manuelle (zone + quartier + repère) suffit.
 * La zone et les frais affichés viennent du serveur (POST /geo/resolve), comme à la commande.
 */
export function LocationPicker({
  value,
  onChange,
  zoneId,
  zones,
  onSuggestion,
}: {
  value: PickedLocation | null
  onChange: (location: PickedLocation | null, resolution: GeoResolution | null) => void
  zoneId?: string | null
  zones?: { name: string; area?: GeoArea | null }[]
  onSuggestion?: (place: GeoPlace) => void
}) {
  const { data: config } = usePublicConfig()
  const maps = config?.maps
  const hasMap = !!maps?.tiles_url
  const [candidate, setCandidate] = useState<PickedLocation | null>(null)
  const [locating, setLocating] = useState(false)
  const [gpsError, setGpsError] = useState<GeolocateError | null>(null)
  const [showMap, setShowMap] = useState(false)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<GeoPlace[] | null>(null)
  const [searching, setSearching] = useState(false)

  const editing = candidate !== null
  // Anti-rafale : quand le client tapote la carte ou fait glisser le repère plusieurs fois, seule la dernière
  // position est vérifiée (chaque vérification peut coûter un appel au fournisseur de géocodage).
  const [checked, setChecked] = useState<PickedLocation | null>(null)
  useEffect(() => {
    if (!candidate) return
    const t = setTimeout(() => setChecked(candidate), candidate.source === 'map' ? 400 : 0)
    return () => clearTimeout(t)
  }, [candidate])
  const settled = !!candidate && checked?.lat === candidate.lat && checked?.lng === candidate.lng
  const resolution = useQuery({
    queryKey: ['geo', 'resolve', checked?.lat, checked?.lng, zoneId ?? null],
    enabled: editing && settled,
    queryFn: () => resolveLocation(checked!.lat, checked!.lng, zoneId),
    staleTime: 5 * 60_000,
    retry: 1,
  })

  const useMyPosition = async () => {
    setGpsError(null)
    setLocating(true)
    try {
      const pos = await geolocate()
      setCandidate({ lat: pos.lat, lng: pos.lng, accuracy_m: pos.accuracy_m, source: 'gps' })
      if (hasMap) setShowMap(true)
    } catch (e) {
      setGpsError(e as GeolocateError)
    } finally {
      setLocating(false)
    }
  }

  const runSearch = async () => {
    if (query.trim().length < 3) return
    setSearching(true)
    try {
      setResults(await searchPlaces(query.trim(), candidate ?? value))
    } catch {
      setResults([])
    } finally {
      setSearching(false)
    }
  }

  const confirm = () => {
    if (!candidate || !settled || !resolution.data?.deliverable) return
    onChange(candidate, resolution.data)
    if (resolution.data.suggestion && onSuggestion) onSuggestion(resolution.data.suggestion)
    setCandidate(null)
    setShowMap(false)
    setResults(null)
  }

  // ---- Position confirmée : résumé ----------------------------------------------------------------
  // Zones tarifaires et périmètre desservi en transparence sur la carte (identité stable : pas de redessin inutile).
  const serviceArea = maps?.service_area
  const overlays = useMemo(
    () => [...(zones ?? []), ...(serviceArea ? [{ name: 'Périmètre desservi', area: serviceArea }] : [])],
    [zones, serviceArea],
  )

  if (value && !editing) {
    return (
      <div className="border-success-600/30 bg-success-50 flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-control)] border px-4 py-3 text-sm">
        <p className="flex items-center gap-2 font-medium">
          <Check className="text-success-600 size-4" aria-hidden /> Position de livraison confirmée
          {value.accuracy_m ? <span className="text-cocoa-500 font-normal">(± {value.accuracy_m} m)</span> : null}
        </p>
        <div className="flex gap-2">
          <Button type="button" variant="secondary" size="sm" onClick={() => setCandidate(value)}>
            Modifier
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onChange(null, null)}
            icon={<X className="size-4" aria-hidden />}
          >
            Retirer
          </Button>
        </div>
      </div>
    )
  }

  const res = settled ? resolution.data : undefined
  const wa = whatsappLink(
    config?.settings?.['business.whatsapp_number'] as string | null,
    'Bonjour MK Délices, mon adresse est hors de vos zones de livraison : est-il possible de me livrer ?',
  )
  return (
    <div className="border-cocoa-100 bg-cream-50/60 space-y-3 rounded-[var(--radius-control)] border p-4">
      <div>
        <p className="flex items-center gap-2 font-semibold">
          <MapPin className="text-caramel-600 size-4" aria-hidden /> Où livrer ?{' '}
          <span className="text-cocoa-500 font-normal">(facultatif, recommandé)</span>
        </p>
        <p className="text-cocoa-500 mt-1 text-sm">
          Votre position aide le livreur à vous trouver. Elle sert uniquement à cette livraison ; vous pouvez aussi
          saisir l'adresse ci-dessous.
        </p>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="secondary"
          onClick={useMyPosition}
          loading={locating}
          icon={<LocateFixed className="size-4" aria-hidden />}
        >
          Utiliser ma position actuelle
        </Button>
        {hasMap && !showMap && (
          <Button
            type="button"
            variant="ghost"
            onClick={() => setShowMap(true)}
            icon={<Crosshair className="size-4" aria-hidden />}
          >
            Choisir sur la carte
          </Button>
        )}
      </div>
      {gpsError && <Notice tone="warning">{GEOLOCATE_MESSAGES[gpsError]}</Notice>}

      {maps?.geocoding && (showMap || editing) && (
        <div>
          <div className="flex gap-2">
            <label className="sr-only" htmlFor="geo-search">
              Rechercher un quartier ou un lieu
            </label>
            <Input
              id="geo-search"
              value={query}
              placeholder="Quartier, rue ou lieu connu (ex. Sacré-Cœur 3)"
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault()
                  runSearch()
                }
              }}
            />
            <Button
              type="button"
              variant="secondary"
              onClick={runSearch}
              loading={searching}
              disabled={query.trim().length < 3}
              aria-label="Rechercher"
              icon={<Search className="size-4" aria-hidden />}
            />
          </div>
          {results && (
            <ul className="divide-cocoa-100 border-cocoa-100 mt-2 divide-y rounded-[var(--radius-control)] border bg-white text-sm">
              {results.length === 0 && (
                <li className="text-cocoa-500 px-3 py-2">
                  Aucun résultat : placez le repère sur la carte ou saisissez l'adresse.
                </li>
              )}
              {results.map((r, i) => (
                <li key={`${r.label}-${i}`}>
                  <button
                    type="button"
                    className="hover:bg-cream-100 w-full px-3 py-2 text-left"
                    onClick={() => {
                      if (r.lat === null || r.lng === null) return
                      setCandidate({ lat: r.lat, lng: r.lng, source: 'search', accuracy_m: null })
                      setShowMap(true)
                      setResults(null)
                    }}
                  >
                    {r.label}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {hasMap && showMap && maps && (
        <Suspense fallback={<Skeleton className="h-72" />}>
          <PinMap
            maps={maps}
            point={candidate}
            zones={overlays}
            label="Carte : touchez ou faites glisser le repère sur votre lieu de livraison"
            onPick={(p) => setCandidate({ lat: round6(p.lat), lng: round6(p.lng), source: 'map', accuracy_m: null })}
          />
          <p className="text-cocoa-500 text-xs">Touchez la carte ou faites glisser le repère pour l'ajuster.</p>
        </Suspense>
      )}

      {editing && (
        <div aria-live="polite" className="space-y-2">
          {candidate.source === 'gps' && (candidate.accuracy_m ?? 0) > ACCURACY_WARNING_M && (
            <Notice tone="warning">
              Position approximative (± {candidate.accuracy_m} m).{' '}
              {hasMap
                ? 'Vérifiez le repère et déplacez-le si besoin.'
                : 'Précisez bien le quartier et le point de repère.'}
            </Notice>
          )}
          {(!settled || resolution.isLoading) && (
            <p role="status" className="text-cocoa-500 flex items-center gap-2 text-sm">
              <Loader2 className="size-4 animate-spin" aria-hidden /> Vérification de la zone de livraison…
            </p>
          )}
          {settled && resolution.isError && (
            <Notice tone="error">
              Vérification impossible pour le moment. Saisissez votre zone et votre adresse ci-dessous.
            </Notice>
          )}
          {res && !res.deliverable && (
            <Notice tone="error" title="Adresse hors de nos zones de livraison">
              {res.in_service_area === false
                ? 'Cette position est en dehors de notre périmètre de livraison.'
                : 'Nous ne livrons pas encore à cet endroit.'}{' '}
              Déplacez le repère si besoin, ou{' '}
              {wa ? (
                <a href={wa} target="_blank" rel="noopener noreferrer" className="font-semibold underline">
                  contactez-nous sur WhatsApp
                </a>
              ) : (
                'contactez-nous'
              )}{' '}
              pour une livraison sur devis.
              {config?.settings?.['ordering.pickup_enabled'] === true && " Le retrait à l'atelier reste possible."}
            </Notice>
          )}
          {res?.deliverable && (
            <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
              <p>
                {res.zone ? (
                  <>
                    Zone <strong>{res.zone.name}</strong> · livraison {formatXof(res.zone.delivery_fee_xof)}
                  </>
                ) : (
                  'Choisissez votre zone ci-dessous.'
                )}
                {res.suggestion?.district && (
                  <span className="text-cocoa-500 block">Quartier suggéré : {res.suggestion.district}</span>
                )}
              </p>
              <Button type="button" onClick={confirm} icon={<Check className="size-4" aria-hidden />}>
                Confirmer cette position
              </Button>
            </div>
          )}
          <button
            type="button"
            className="text-cocoa-500 text-sm underline"
            onClick={() => {
              setCandidate(null)
              setShowMap(false)
              setResults(null)
            }}
          >
            Annuler
          </button>
        </div>
      )}
    </div>
  )
}
