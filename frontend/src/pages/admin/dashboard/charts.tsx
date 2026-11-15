import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card } from '@/components/ui/Display'
import { EmptyState } from '@/components/ui/Feedback'
import { formatXof } from '@/lib/format'
import type { Kpis, StatsSummary } from './types'

/**
 * Couleurs de séries (dataviz) : une seule série par graphique, pas de double axe.
 * Chiffre d'affaires = framboise, commandes = cacao. Le texte reste dans les encres de texte.
 */
const REVENUE_COLOR = '#b74264'
const ORDERS_COLOR = '#7a4d40'
const GRID = '#efe4d8'
const AXIS_TEXT = '#7a4d40'

const ORDER_STATUS_LABELS: Record<string, string> = {
  draft: 'Brouillon',
  pending_confirmation: 'À confirmer',
  accepted: 'Acceptée',
  preparing: 'En préparation',
  ready: 'Prête',
  assigned: 'Confiée au livreur',
  picked_up: 'En livraison',
  delivered: 'Livrée',
  delivery_failed: 'Échec de livraison',
  cancelled: 'Annulée',
  rejected: 'Refusée',
}

function shortDay(day: string): string {
  const [, m, d] = day.split('-')
  return `${d}/${m}`
}

function compactXof(v: number): string {
  if (Math.abs(v) >= 1_000_000) return `${(v / 1_000_000).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} M`
  if (Math.abs(v) >= 1_000) return `${Math.round(v / 1_000).toLocaleString('fr-FR')} k`
  return String(v)
}

interface TooltipPayload {
  active?: boolean
  label?: string
  payload?: { value: number }[]
}

function ChartTooltip({ active, payload, label, kind }: TooltipPayload & { kind: 'revenue' | 'orders' }) {
  if (!active || !payload?.length) return null
  const value = payload[0].value
  return (
    <div className="rounded-[var(--radius-control)] border border-cocoa-100 bg-white px-3 py-2 text-sm shadow-soft">
      <p className="font-semibold tabular-nums">{kind === 'revenue' ? formatXof(value) : `${value} commande${value > 1 ? 's' : ''}`}</p>
      <p className="text-xs text-cocoa-500">
        <span className="mr-1.5 inline-block h-0.5 w-3 align-middle" style={{ background: kind === 'revenue' ? REVENUE_COLOR : ORDERS_COLOR }} />
        {kind === 'revenue' ? 'CA net' : 'Commandes confirmées'} · {label}
      </p>
    </div>
  )
}

/** Deux graphiques distincts (CA net et nombre de commandes) avec vue tableau accessible. */
export function TimeseriesCharts({ data }: { data: StatsSummary['timeseries'] }) {
  const [table, setTable] = useState(false)
  const rows = data.map((r) => ({ ...r, label: shortDay(r.day) }))
  const empty = rows.every((r) => r.revenue_net_xof === 0 && r.orders === 0)

  return (
    <Card className="p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold">Évolution des ventes</h2>
          <p className="text-xs text-cocoa-500">CA net des commandes payées (remboursements déduits) et commandes confirmées, par jour (heure de Dakar).</p>
        </div>
        <button type="button" onClick={() => setTable((t) => !t)} className="rounded-full border border-cocoa-100 px-3 py-1.5 text-xs font-medium hover:bg-cream-200" aria-pressed={table}>
          {table ? 'Voir les graphiques' : 'Voir le tableau'}
        </button>
      </div>
      {empty && !table ? (
        <EmptyState title="Aucune vente sur la période" description="Les graphiques s'afficheront dès les premières commandes confirmées." />
      ) : table ? (
        <div className="max-h-80 overflow-auto">
          <table className="w-full text-sm">
            <thead className="sticky top-0 bg-white text-left text-xs text-cocoa-500">
              <tr>
                <th className="py-2 pr-3 font-medium">Jour</th>
                <th className="py-2 pr-3 text-right font-medium">CA net</th>
                <th className="py-2 text-right font-medium">Commandes</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.day} className="border-t border-cocoa-100/60">
                  <td className="py-1.5 pr-3">{r.day}</td>
                  <td className="py-1.5 pr-3 text-right tabular-nums">{formatXof(r.revenue_net_xof)}</td>
                  <td className="py-1.5 text-right tabular-nums">{r.orders}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          <figure>
            <figcaption className="mb-2 text-sm font-medium">Chiffre d'affaires net (FCFA)</figcaption>
            <div className="h-56">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={rows} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap={2}>
                  <CartesianGrid vertical={false} stroke={GRID} />
                  <XAxis dataKey="label" tick={{ fill: AXIS_TEXT, fontSize: 11 }} tickLine={false} axisLine={{ stroke: GRID }} minTickGap={16} />
                  <YAxis tickFormatter={compactXof} tick={{ fill: AXIS_TEXT, fontSize: 11 }} tickLine={false} axisLine={false} width={44} />
                  <Tooltip cursor={{ fill: '#f8ecdc' }} content={<ChartTooltip kind="revenue" />} />
                  <Bar dataKey="revenue_net_xof" fill={REVENUE_COLOR} radius={[4, 4, 0, 0]} maxBarSize={28} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </figure>
          <figure>
            <figcaption className="mb-2 text-sm font-medium">Commandes confirmées</figcaption>
            <div className="h-56">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={rows} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
                  <CartesianGrid vertical={false} stroke={GRID} />
                  <XAxis dataKey="label" tick={{ fill: AXIS_TEXT, fontSize: 11 }} tickLine={false} axisLine={{ stroke: GRID }} minTickGap={16} />
                  <YAxis allowDecimals={false} tick={{ fill: AXIS_TEXT, fontSize: 11 }} tickLine={false} axisLine={false} width={32} />
                  <Tooltip cursor={{ stroke: '#b08b80', strokeWidth: 1 }} content={<ChartTooltip kind="orders" />} />
                  <Line type="monotone" dataKey="orders" stroke={ORDERS_COLOR} strokeWidth={2} dot={false} activeDot={{ r: 5, stroke: '#fff', strokeWidth: 2 }} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </figure>
        </div>
      )}
    </Card>
  )
}

/** Barres horizontales (une seule série) pour les classements : libellés et valeurs en encre de texte. */
export function RankedBars({ title, items, empty }: { title: string; items: { key: string; label: string; value: number; detail?: string }[]; empty: string }) {
  const max = Math.max(1, ...items.map((i) => i.value))
  return (
    <Card className="p-5">
      <h2 className="mb-4 text-lg font-semibold">{title}</h2>
      {items.length === 0 ? (
        <p className="py-6 text-center text-sm text-cocoa-500">{empty}</p>
      ) : (
        <ul className="space-y-3">
          {items.map((i) => (
            <li key={i.key} title={`${i.label} : ${i.value}${i.detail ? ` · ${i.detail}` : ''}`}>
              <div className="mb-1 flex justify-between gap-3 text-sm">
                <span className="truncate">{i.label}</span>
                <span className="shrink-0 font-semibold tabular-nums">
                  {i.value}
                  {i.detail && <span className="ml-2 font-normal text-cocoa-500">{i.detail}</span>}
                </span>
              </div>
              <div className="h-2 rounded-full bg-cream-200">
                <div className="h-2 rounded-full" style={{ width: `${(i.value / max) * 100}%`, background: REVENUE_COLOR }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

export function statusItems(breakdown: StatsSummary['status_breakdown']) {
  return breakdown.map((s) => ({ key: s.status, label: ORDER_STATUS_LABELS[s.status] ?? s.status, value: s.count }))
}

export function topProductItems(top: StatsSummary['top_products']) {
  return top.map((p) => ({ key: p.product_id, label: p.name, value: p.quantity, detail: formatXof(p.revenue_xof) }))
}

export type { Kpis }
