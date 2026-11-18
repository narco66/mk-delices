/*
 * Cartes Leaflet (chargées à la demande : jamais dans le paquet principal, pour les mobiles en 3G).
 * Fond de carte : fournisseur commercial configuré côté serveur (DEC-GEO-01), jamais les serveurs publics OSM.
 */
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useEffect, useRef } from 'react'
import type { GeoArea, MapsConfig } from '@/types/api'

const BRAND = '#5b342b'
const NO_ZONES: { name: string; area?: GeoArea | null }[] = []
const ACCENT = '#c2410c'

/** Repère en CSS pur (pas d'image externe à charger, compatible CSP). */
function pinIcon(color = BRAND) {
  return L.divIcon({
    className: '',
    html: `<span style="display:block;width:26px;height:26px;border-radius:50% 50% 50% 0;background:${color};border:3px solid #fff;transform:rotate(-45deg);box-shadow:0 2px 6px rgba(0,0,0,.35)"></span>`,
    iconSize: [26, 26],
    iconAnchor: [13, 26],
  })
}

function vertexIcon() {
  return L.divIcon({
    className: '',
    html: `<span style="display:block;width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid ${ACCENT}"></span>`,
    iconSize: [14, 14],
    iconAnchor: [7, 7],
  })
}

function createMap(el: HTMLElement, maps: MapsConfig): L.Map {
  const b = maps.bounds
  const map = L.map(el, {
    center: [maps.default_center.lat, maps.default_center.lng],
    zoom: maps.default_center.zoom,
    maxBounds: L.latLngBounds([b.min_lat - 0.2, b.min_lng - 0.2], [b.max_lat + 0.2, b.max_lng + 0.2]),
    minZoom: 9,
  })
  L.tileLayer(maps.tiles_url!, { attribution: maps.attribution, maxZoom: 19, crossOrigin: true }).addTo(map)
  return map
}

/** GeoJSON [lng, lat] → anneaux Leaflet [lat, lng]. */
function toLatLngs(area: GeoArea): L.LatLngExpression[][][] {
  const polygons = area.type === 'Polygon' ? [area.coordinates] : area.coordinates
  return polygons.map((rings) => rings.map((ring) => ring.map(([lng, lat]) => [lat, lng] as L.LatLngTuple)))
}

/** Carte avec repère déplaçable (checkout, carnet d'adresses). */
export function PinMap({
  maps,
  point,
  onPick,
  zones = NO_ZONES,
  label,
}: {
  maps: MapsConfig
  point: { lat: number; lng: number } | null
  onPick: (p: { lat: number; lng: number }) => void
  zones?: { name: string; area?: GeoArea | null }[]
  label: string
}) {
  const el = useRef<HTMLDivElement>(null)
  const map = useRef<L.Map | null>(null)
  const marker = useRef<L.Marker | null>(null)
  const pick = useRef(onPick)
  useEffect(() => {
    pick.current = onPick
  }, [onPick])

  useEffect(() => {
    if (!el.current) return
    const m = createMap(el.current, maps)
    m.on('click', (e: L.LeafletMouseEvent) => pick.current({ lat: e.latlng.lat, lng: e.latlng.lng }))
    map.current = m
    return () => {
      m.remove()
      map.current = null
      marker.current = null
    }
  }, [maps])

  // Zones desservies en transparence : le client voit s'il est dedans.
  useEffect(() => {
    const m = map.current
    if (!m) return
    const layers = zones
      .filter((z) => z.area)
      .map((z) =>
        L.polygon(toLatLngs(z.area!), { color: BRAND, weight: 1, fillOpacity: 0.06, interactive: false }).addTo(m),
      )
    return () => layers.forEach((l) => l.remove())
  }, [zones])

  useEffect(() => {
    const m = map.current
    if (!m) return
    if (!point) {
      marker.current?.remove()
      marker.current = null
      return
    }
    if (!marker.current) {
      marker.current = L.marker([point.lat, point.lng], {
        draggable: true,
        icon: pinIcon(),
        keyboard: true,
        title: 'Position de livraison',
      }).addTo(m)
      marker.current.on('dragend', () => {
        const p = marker.current!.getLatLng()
        pick.current({ lat: p.lat, lng: p.lng })
      })
      m.setView([point.lat, point.lng], Math.max(m.getZoom(), 16))
    } else {
      marker.current.setLatLng([point.lat, point.lng])
      if (!m.getBounds().pad(-0.1).contains([point.lat, point.lng])) m.panTo([point.lat, point.lng])
    }
  }, [point])

  return (
    <div
      ref={el}
      role="application"
      aria-label={label}
      className="border-cocoa-100 h-72 w-full overflow-hidden rounded-[var(--radius-control)] border sm:h-80"
    />
  )
}

/** Éditeur de contour de zone (back-office) : clic = ajouter un point, glisser = déplacer. */
export function AreaEditor({
  maps,
  points,
  onChange,
  others = NO_ZONES,
}: {
  maps: MapsConfig
  points: [number, number][]
  onChange: (points: [number, number][]) => void
  others?: { name: string; area?: GeoArea | null }[]
}) {
  const el = useRef<HTMLDivElement>(null)
  const map = useRef<L.Map | null>(null)
  const change = useRef(onChange)
  const current = useRef(points)
  useEffect(() => {
    change.current = onChange
    current.current = points
  }, [onChange, points])

  useEffect(() => {
    if (!el.current) return
    const m = createMap(el.current, maps)
    m.on('click', (e: L.LeafletMouseEvent) => change.current([...current.current, [e.latlng.lat, e.latlng.lng]]))
    map.current = m
    if (current.current.length) m.fitBounds(L.latLngBounds(current.current), { padding: [30, 30] })
    return () => {
      m.remove()
      map.current = null
    }
  }, [maps])

  useEffect(() => {
    const m = map.current
    if (!m) return
    const layers: L.Layer[] = others
      .filter((z) => z.area)
      .map((z) =>
        L.polygon(toLatLngs(z.area!), {
          color: '#6b7280',
          weight: 1,
          dashArray: '4',
          fillOpacity: 0.05,
          interactive: false,
        })
          .bindTooltip(z.name)
          .addTo(m),
      )
    return () => layers.forEach((l) => l.remove())
  }, [others])

  useEffect(() => {
    const m = map.current
    if (!m) return
    const layers: L.Layer[] = []
    if (points.length >= 2)
      layers.push(L.polygon(points, { color: ACCENT, weight: 2, fillOpacity: 0.15, interactive: false }).addTo(m))
    points.forEach((p, i) => {
      const v = L.marker(p, { draggable: true, icon: vertexIcon(), title: `Point ${i + 1}` }).addTo(m)
      v.on('dragend', () => {
        const ll = v.getLatLng()
        change.current(current.current.map((q, j) => (j === i ? [ll.lat, ll.lng] : q)))
      })
      layers.push(v)
    })
    return () => layers.forEach((l) => l.remove())
  }, [points])

  return (
    <div
      ref={el}
      role="application"
      aria-label="Carte : cliquez pour ajouter un point au contour de la zone"
      className="border-cocoa-100 h-96 w-full overflow-hidden rounded-[var(--radius-control)] border"
    />
  )
}
