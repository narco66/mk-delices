import { Card } from '@/components/ui/Display'
import { formatXof } from '@/lib/format'
import type { StatsSummary } from './types'

const pct = (n: number, total: number) => (total > 0 ? `${Math.round((n / total) * 100)} %` : '—')

/** Indicateurs par zone (docs/audit-geolocalisation) : agrégats uniquement, aucune position individuelle. */
export function ZoneTable({ rows }: { rows: StatsSummary['by_zone'] }) {
  if (rows.length === 0) return null
  return (
    <Card className="overflow-x-auto">
      <h2 className="px-5 pt-5 font-semibold">Par zone de livraison</h2>
      <table className="mt-3 w-full min-w-[860px] text-sm">
        <thead className="border-y border-cocoa-100 bg-cream-100 text-left text-xs tracking-wide text-cocoa-500 uppercase">
          <tr>
            <th className="px-4 py-2">Zone</th>
            <th className="px-4 py-2 text-right">Commandes</th>
            <th className="px-4 py-2 text-right">CA net</th>
            <th className="px-4 py-2 text-right">Frais livraison</th>
            <th className="px-4 py-2 text-right">Livrées / échecs</th>
            <th className="px-4 py-2 text-right">En retard</th>
            <th className="px-4 py-2 text-right">Trajet moyen</th>
            <th className="px-4 py-2 text-right" title="Commandes avec une position GPS ou carte">Avec position</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.zone_id ?? 'none'} className="border-b border-cocoa-100/60 last:border-0">
              <td className="px-4 py-2 font-medium">{r.zone_name}</td>
              <td className="px-4 py-2 text-right tabular-nums">{r.orders}</td>
              <td className="px-4 py-2 text-right tabular-nums">{formatXof(r.revenue_net_xof)}</td>
              <td className="px-4 py-2 text-right tabular-nums">{formatXof(r.delivery_fees_xof)}</td>
              <td className="px-4 py-2 text-right tabular-nums">
                {r.delivered} / {r.failed}
              </td>
              <td className="px-4 py-2 text-right tabular-nums">{r.late}</td>
              <td className="px-4 py-2 text-right tabular-nums">{r.avg_trip_minutes !== null ? `${r.avg_trip_minutes} min` : '—'}</td>
              <td className="px-4 py-2 text-right tabular-nums">{pct(r.positioned, r.orders)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="px-5 pb-4 text-xs text-cocoa-500">
        En retard = livrée après la fin du créneau · Trajet = livraison − enlèvement · « Avec position » = part des commandes dont le client a indiqué sa position.
      </p>
    </Card>
  )
}
