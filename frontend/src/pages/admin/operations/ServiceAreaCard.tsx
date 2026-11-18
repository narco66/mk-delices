import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Map as MapIcon } from 'lucide-react'
import { lazy, Suspense, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal } from '@/components/ui/Display'
import { errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Textarea } from '@/components/ui/Form'
import { usePublicConfig } from '@/hooks/usePublicData'
import { api, ApiError } from '@/lib/api'
import type { GeoArea, Zone } from '@/types/api'

const AreaEditor = lazy(() => import('@/features/maps/LeafletMaps').then((m) => ({ default: m.AreaEditor })))

/** Nombre de points d'un contour (tous anneaux confondus). */
function vertexCount(area: GeoArea): number {
  const polygons = area.type === 'Polygon' ? [area.coordinates] : area.coordinates
  return polygons.reduce((n, rings) => n + rings.reduce((m, ring) => m + ring.length, 0), 0)
}

/** Polygone simple ↔ points [lat, lng] de l'éditeur (null : contour à plusieurs parties, non éditable à la souris). */
function toPoints(area: GeoArea | null): [number, number][] | null {
  if (!area) return []
  if (area.type !== 'Polygon' || area.coordinates.length !== 1) return null
  const ring = area.coordinates[0].map(([lng, lat]) => [lat, lng] as [number, number])
  return ring.length > 1 && ring[0][0] === ring[ring.length - 1][0] && ring[0][1] === ring[ring.length - 1][1] ? ring.slice(0, -1) : ring
}

/**
 * Périmètre desservi (ex. ville de Dakar) : une position hors de ce contour ne peut pas commander en ligne.
 * Distinct des zones tarifaires, qui fixent les frais à l'intérieur du périmètre.
 */
export function ServiceAreaCard({ canManage, zones }: { canManage: boolean; zones: Zone[] }) {
  const [editing, setEditing] = useState(false)
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'service-area'],
    queryFn: async () => (await api.get<{ data: { area: GeoArea | null } }>('/admin/delivery/service-area')).data,
  })
  const area = data?.area ?? null

  return (
    <Card className="flex flex-wrap items-center justify-between gap-4 p-5">
      <div className="space-y-1">
        <h2 className="flex items-center gap-2 font-semibold">
          <MapIcon className="size-4 text-caramel-600" aria-hidden /> Périmètre desservi
          {isLoading ? null : area ? <Badge tone="success">Défini · {vertexCount(area)} points</Badge> : <Badge>Non défini</Badge>}
        </h2>
        <p className="max-w-2xl text-sm text-cocoa-500">
          {area
            ? 'Une position hors de ce contour ne peut pas commander en ligne (proposition de contact WhatsApp). À l’intérieur, la zone tarifaire est celle dessinée, sinon celle choisie par le client.'
            : 'Aucun contour : toute position de la région de Dakar est acceptée et le client choisit sa zone tarifaire.'}
        </p>
      </div>
      {canManage && (
        <Button variant="secondary" onClick={() => setEditing(true)}>
          {area ? 'Modifier le périmètre' : 'Définir le périmètre'}
        </Button>
      )}
      {editing && <ServiceAreaEditor area={area} zones={zones} onClose={() => setEditing(false)} />}
    </Card>
  )
}

function ServiceAreaEditor({ area, zones, onClose }: { area: GeoArea | null; zones: Zone[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const { data: config } = usePublicConfig()
  const maps = config?.maps
  // Contour à plusieurs parties : conservé tel quel tant qu'il n'est pas remplacé (import) ou effacé.
  const [draft, setDraft] = useState<GeoArea | null>(area)
  const points = toPoints(draft)
  const [geojson, setGeojson] = useState('')
  const [error, setError] = useState<string | null>(null)

  const setPoints = (p: [number, number][]) =>
    setDraft(p.length >= 3 ? { type: 'Polygon', coordinates: [[...p.map(([lat, lng]) => [lng, lat]), [p[0][1], p[0][0]]]] } : p.length ? { type: 'Polygon', coordinates: [p.map(([lat, lng]) => [lng, lat])] } : null)

  const importGeojson = () => {
    try {
      const parsed = JSON.parse(geojson)
      const geometry = (parsed.type === 'Feature' ? parsed.geometry : parsed.type === 'FeatureCollection' ? parsed.features?.[0]?.geometry : parsed) as GeoArea
      if (geometry?.type !== 'Polygon' && geometry?.type !== 'MultiPolygon') throw new Error('invalid')
      setDraft(geometry)
      setError(null)
    } catch {
      setError('GeoJSON non reconnu : Polygon ou MultiPolygon attendu (coordonnées [longitude, latitude]).')
    }
  }

  // Contour en cours de tracé (1 ou 2 points) : enregistrement impossible, pour ne jamais effacer par erreur.
  const incomplete = points !== null && points.length > 0 && points.length < 3
  const save = useMutation({
    mutationFn: () => api.put('/admin/delivery/service-area', { area: draft }),
    onSuccess: () => {
      toast.success('Périmètre desservi enregistré')
      queryClient.invalidateQueries({ queryKey: ['admin', 'service-area'] })
      queryClient.invalidateQueries({ queryKey: ['config'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError && e.fieldErrors.area) setError(e.fieldErrors.area[0])
      toast.error(errorMessage(e))
    },
  })

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title="Périmètre desservi"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button loading={save.isPending} disabled={incomplete} onClick={() => save.mutate()}>
            Enregistrer
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        {points === null ? (
          <Notice tone="info">Contour à plusieurs parties : conservé tel quel. Importez un autre contour ou effacez-le pour en dessiner un nouveau.</Notice>
        ) : maps?.tiles_url ? (
          <Suspense fallback={<Skeleton className="h-96" />}>
            <AreaEditor maps={maps} points={points} onChange={setPoints} others={zones} />
          </Suspense>
        ) : (
          <Notice tone="warning">Fond de carte non configuré (MAPS_TILES_URL) : importez un contour GeoJSON ci-dessous.</Notice>
        )}
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="text-cocoa-500">
            {draft ? `${vertexCount(draft)} point(s)` : 'Aucun contour'}
            {incomplete ? ' (3 minimum)' : ''}
          </span>
          {points !== null && (
            <Button type="button" size="sm" variant="secondary" disabled={points.length === 0} onClick={() => setPoints(points.slice(0, -1))}>
              Retirer le dernier point
            </Button>
          )}
          <Button type="button" size="sm" variant="ghost" disabled={!draft} onClick={() => setDraft(null)}>
            Effacer le contour
          </Button>
        </div>
        <details className="text-sm">
          <summary className="cursor-pointer text-cocoa-500">Importer un contour GeoJSON</summary>
          <div className="mt-2 space-y-2">
            <Textarea aria-label="Périmètre GeoJSON" value={geojson} onChange={(e) => setGeojson(e.target.value)} rows={4} placeholder="Polygon ou MultiPolygon, coordonnées [longitude, latitude]" />
            <Button type="button" size="sm" variant="secondary" onClick={importGeojson} disabled={!geojson.trim()}>
              Importer
            </Button>
          </div>
        </details>
        {error && <Notice tone="error">{error}</Notice>}
      </div>
    </Modal>
  )
}
