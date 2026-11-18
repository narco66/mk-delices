import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MapPin, Plus } from 'lucide-react'
import { lazy, Suspense, useState } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Button } from '@/components/ui/Button'
import { Badge, Card, Modal, PageHeader } from '@/components/ui/Display'
import { EmptyState, ErrorState, errorMessage, Notice, Skeleton } from '@/components/ui/Feedback'
import { Checkbox, Field, Input, Textarea } from '@/components/ui/Form'
import { useAuth } from '@/features/auth/AuthContext'
import { usePublicConfig } from '@/hooks/usePublicData'
import { api, ApiError } from '@/lib/api'
import { formatXof } from '@/lib/format'
import type { GeoArea, Zone } from '@/types/api'
import { ServiceAreaCard } from './ServiceAreaCard'

const AreaEditor = lazy(() => import('@/features/maps/LeafletMaps').then((m) => ({ default: m.AreaEditor })))

/** Contour simple (un polygone sans trou) <-> points [lat, lng] de l'éditeur ; null = contour complexe. */
function areaToPoints(area: GeoArea | null | undefined): [number, number][] | null {
  if (!area) return []
  if (area.type !== 'Polygon' || area.coordinates.length !== 1) return null
  const ring = area.coordinates[0].map(([lng, lat]) => [lat, lng] as [number, number])
  const closed = ring.length > 1 && ring[0][0] === ring[ring.length - 1][0] && ring[0][1] === ring[ring.length - 1][1]
  return closed ? ring.slice(0, -1) : ring
}

function pointsToArea(points: [number, number][]): GeoArea | null {
  if (points.length < 3) return null
  const ring = points.map(([lat, lng]) => [lng, lat])
  return { type: 'Polygon', coordinates: [[...ring, ring[0]]] }
}

export function ZonesPage() {
  const { can } = useAuth()
  const canManage = can('zones.manage')
  const [editing, setEditing] = useState<Zone | 'new' | null>(null)
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin', 'zones'],
    queryFn: async () => (await api.get<{ data: Zone[] }>('/admin/delivery/zones')).data,
  })

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Logistique"
        title="Zones & tarifs"
        description="Zones desservies, frais facturés au client et rémunération des livreurs."
        actions={
          canManage && (
            <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
              Nouvelle zone
            </Button>
          )
        }
      />
      <Notice>Les frais de livraison des commandes déjà confirmées restent figés : une modification ne s'applique qu'aux nouveaux devis.</Notice>
      {!canManage && <Notice tone="warning">Consultation seule : la modification des zones est réservée à l'administrateur.</Notice>}
      <ServiceAreaCard canManage={canManage} zones={data ?? []} />

      {isLoading ? (
        <Skeleton className="h-64" />
      ) : isError || !data ? (
        <ErrorState error={error} onRetry={() => refetch()} />
      ) : data.length === 0 ? (
        <EmptyState icon={<MapPin className="size-6" />} title="Aucune zone" description="Aucune zone de livraison n'est encore définie." />
      ) : (
        <Card className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="border-b border-cocoa-100 bg-cream-100 text-left text-xs tracking-wide text-cocoa-500 uppercase">
              <tr>
                <th className="px-4 py-3">Zone</th>
                <th className="px-4 py-3 text-right">Frais client</th>
                <th className="px-4 py-3 text-right">Minimum</th>
                <th className="px-4 py-3 text-right">Rémunération livreur</th>
                <th className="px-4 py-3 text-right">Délai suppl.</th>
                <th className="px-4 py-3">Contour</th>
                <th className="px-4 py-3">Livreurs</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {data.map((z) => (
                <tr key={z.id} className="border-b border-cocoa-100/60 last:border-0">
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap items-center gap-2 font-semibold">
                      {z.name}
                      {!z.active && <Badge>Inactive</Badge>}
                      {z.is_demo && <Badge tone="caramel">Démo</Badge>}
                    </div>
                    {z.districts && <div className="text-xs text-cocoa-500">{z.districts}</div>}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatXof(z.delivery_fee_xof)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{z.min_order_xof ? formatXof(z.min_order_xof) : '—'}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatXof(z.partner_fee_xof ?? 0)}</td>
                  <td className="px-4 py-3 text-right">{z.extra_lead_minutes ? `${z.extra_lead_minutes} min` : '—'}</td>
                  <td className="px-4 py-3">{z.area ? <Badge tone="success">Dessiné</Badge> : <span className="text-cocoa-500">Non</span>}</td>
                  <td className="px-4 py-3">{z.partners_count ?? 0}</td>
                  <td className="px-4 py-3 text-right">
                    {canManage && (
                      <Button size="sm" variant="secondary" onClick={() => setEditing(z)}>
                        Modifier
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
      {editing && canManage && (
        <ZoneForm zone={editing === 'new' ? null : editing} others={(data ?? []).filter((z) => editing === 'new' || z.id !== editing.id)} onClose={() => setEditing(null)} />
      )}
    </div>
  )
}

const intString = (required: boolean) =>
  required ? z.string().regex(/^\d+$/, 'Entier positif requis') : z.union([z.literal(''), z.string().regex(/^\d+$/, 'Entier positif')])

const schema = z.object({
  name: z.string().trim().min(2, 'Nom requis'),
  districts: z.string().optional(),
  description: z.string().optional(),
  delivery_fee_xof: intString(true),
  min_order_xof: intString(false),
  partner_fee_xof: intString(true),
  extra_lead_minutes: intString(false),
  position: intString(false),
  active: z.boolean(),
})
type Form = z.infer<typeof schema>

function ZoneForm({ zone, others, onClose }: { zone: Zone | null; others: Zone[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const { data: config } = usePublicConfig()
  const maps = config?.maps
  const initialPoints = areaToPoints(zone?.area)
  const [points, setPoints] = useState<[number, number][]>(initialPoints ?? [])
  const [areaTouched, setAreaTouched] = useState(false)
  const [geojson, setGeojson] = useState('')
  const [areaError, setAreaError] = useState<string | null>(null)
  const editPoints = (p: [number, number][]) => {
    setPoints(p)
    setAreaTouched(true)
  }
  const importGeojson = () => {
    try {
      const parsed = JSON.parse(geojson)
      const geometry = (parsed.type === 'Feature' ? parsed.geometry : parsed.type === 'FeatureCollection' ? parsed.features?.[0]?.geometry : parsed) as GeoArea
      const pts = areaToPoints(geometry)
      if (!pts || pts.length < 3) throw new Error('invalid')
      editPoints(pts)
      setAreaError(null)
    } catch {
      setAreaError('GeoJSON non reconnu : un seul polygone (Polygon) sans trou est attendu.')
    }
  }
  const { register, handleSubmit, formState, setError } = useForm<Form>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: zone?.name ?? '',
      districts: zone?.districts ?? '',
      description: zone?.description ?? '',
      delivery_fee_xof: String(zone?.delivery_fee_xof ?? ''),
      min_order_xof: zone?.min_order_xof ? String(zone.min_order_xof) : '',
      partner_fee_xof: String(zone?.partner_fee_xof ?? '0'),
      extra_lead_minutes: zone?.extra_lead_minutes ? String(zone.extra_lead_minutes) : '',
      position: zone?.position ? String(zone.position) : '',
      active: zone?.active ?? true,
    },
  })
  const save = useMutation({
    mutationFn: (v: Form) => {
      const body = {
        name: v.name,
        districts: v.districts || null,
        description: v.description || null,
        delivery_fee_xof: Number(v.delivery_fee_xof),
        min_order_xof: v.min_order_xof ? Number(v.min_order_xof) : null,
        partner_fee_xof: Number(v.partner_fee_xof),
        extra_lead_minutes: v.extra_lead_minutes ? Number(v.extra_lead_minutes) : 0,
        position: v.position ? Number(v.position) : 0,
        active: v.active,
        // Contour envoyé seulement s'il a été modifié (un contour complexe existant reste intact).
        ...(areaTouched ? { area: pointsToArea(points) } : {}),
      }
      return zone ? api.put(`/admin/delivery/zones/${zone.id}`, body) : api.post('/admin/delivery/zones', body)
    },
    onSuccess: () => {
      toast.success(zone ? 'Zone mise à jour' : 'Zone créée')
      queryClient.invalidateQueries({ queryKey: ['admin', 'zones'] })
      queryClient.invalidateQueries({ queryKey: ['zones'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) {
        Object.entries(e.fieldErrors).forEach(([k, m]) => (k === 'area' ? setAreaError(m[0]) : setError(k as keyof Form, { message: m[0] })))
      }
      toast.error(errorMessage(e))
    },
  })
  const err = formState.errors

  return (
    <Modal
      open
      onOpenChange={(o) => !o && onClose()}
      title={zone ? 'Modifier la zone' : 'Nouvelle zone'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            Annuler
          </Button>
          <Button loading={save.isPending} onClick={handleSubmit((v) => save.mutate(v))}>
            Enregistrer
          </Button>
        </div>
      }
    >
      <form className="grid gap-4 sm:grid-cols-2" onSubmit={handleSubmit((v) => save.mutate(v))}>
        <Field label="Nom" required className="sm:col-span-2" error={err.name?.message}>{(p) => <Input {...p} {...register('name')} />}</Field>
        <Field label="Quartiers couverts" className="sm:col-span-2" error={err.districts?.message}>{(p) => <Textarea {...p} {...register('districts')} placeholder="Ex. Plateau, Médina" />}</Field>
        <Field label="Frais client (FCFA)" required error={err.delivery_fee_xof?.message}>{(p) => <Input {...p} inputMode="numeric" {...register('delivery_fee_xof')} />}</Field>
        <Field label="Commande minimum (FCFA)" error={err.min_order_xof?.message}>{(p) => <Input {...p} inputMode="numeric" {...register('min_order_xof')} />}</Field>
        <Field label="Rémunération livreur (FCFA)" required error={err.partner_fee_xof?.message}>{(p) => <Input {...p} inputMode="numeric" {...register('partner_fee_xof')} />}</Field>
        <Field label="Délai supplémentaire (min)" error={err.extra_lead_minutes?.message}>{(p) => <Input {...p} inputMode="numeric" {...register('extra_lead_minutes')} />}</Field>
        <Field label="Ordre d'affichage" hint="Si deux contours se chevauchent, le plus petit numéro l'emporte." error={err.position?.message}>{(p) => <Input {...p} inputMode="numeric" {...register('position')} />}</Field>
        <div className="flex items-end">
          <Checkbox label="Zone active" {...register('active')} />
        </div>
        <Field label="Description" className="sm:col-span-2">{(p) => <Textarea {...p} {...register('description')} />}</Field>
        <fieldset className="space-y-3 sm:col-span-2">
          <legend className="font-semibold">Contour sur la carte</legend>
          <p className="text-sm text-cocoa-500">
            Avec un contour, la zone (et ses frais) est déterminée automatiquement par la position du client ; une position hors de tous les contours ne peut pas être commandée en ligne. Sans contour, le client choisit la zone lui-même.
          </p>
          {initialPoints === null && !areaTouched ? (
            <Notice tone="info">Contour complexe (plusieurs parties) conservé. Effacez-le pour en dessiner un nouveau.</Notice>
          ) : maps?.tiles_url ? (
            <Suspense fallback={<Skeleton className="h-96" />}>
              <AreaEditor maps={maps} points={points} onChange={editPoints} others={others} />
            </Suspense>
          ) : (
            <Notice tone="warning">Fond de carte non configuré (MAPS_TILES_URL) : importez un contour GeoJSON ci-dessous.</Notice>
          )}
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span className="text-cocoa-500">
              {points.length} point(s){points.length > 0 && points.length < 3 ? ' (3 minimum)' : ''}
            </span>
            <Button type="button" size="sm" variant="secondary" disabled={points.length === 0} onClick={() => editPoints(points.slice(0, -1))}>
              Retirer le dernier point
            </Button>
            <Button type="button" size="sm" variant="ghost" disabled={points.length === 0 && initialPoints !== null} onClick={() => editPoints([])}>
              Effacer le contour
            </Button>
          </div>
          <details className="text-sm">
            <summary className="cursor-pointer text-cocoa-500">Importer un contour GeoJSON</summary>
            <div className="mt-2 space-y-2">
              <Textarea aria-label="Contour GeoJSON" value={geojson} onChange={(e) => setGeojson(e.target.value)} rows={4} placeholder="Polygon GeoJSON, coordonnées [longitude, latitude]" />
              <Button type="button" size="sm" variant="secondary" onClick={importGeojson} disabled={!geojson.trim()}>
                Importer
              </Button>
            </div>
          </details>
          {areaError && <Notice tone="error">{areaError}</Notice>}
        </fieldset>
      </form>
    </Modal>
  )
}
