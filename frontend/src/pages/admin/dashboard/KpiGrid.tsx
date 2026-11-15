import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Card } from '@/components/ui/Display'
import { cn } from '@/lib/cn'
import { formatXof, percent } from '@/lib/format'
import type { Kpis } from './types'

export function StatTile({ label, value, hint, to, tone = 'default' }: { label: string; value: ReactNode; hint?: ReactNode; to?: string; tone?: 'default' | 'alert' }) {
  const body = (
    <>
      <p className="text-xs font-semibold tracking-wide text-cocoa-500 uppercase">{label}</p>
      <p className={cn('mt-2 font-display text-3xl font-semibold tabular-nums', tone === 'alert' ? 'text-raspberry-600' : 'text-cocoa-900')}>{value}</p>
      {hint && <p className="mt-1 text-xs text-cocoa-500">{hint}</p>}
    </>
  )
  if (to) {
    return (
      <Link to={to} className="block rounded-[var(--radius-card)] border border-cocoa-100/70 bg-white p-4 shadow-soft transition hover:-translate-y-0.5 hover:shadow-lift focus-visible:-translate-y-0.5">
        {body}
      </Link>
    )
  }
  return <Card className="p-4">{body}</Card>
}

/** Indicateurs PRD §2 : null => « — » (jamais de chiffre inventé). */
export function KpiGrid({ kpis }: { kpis: Kpis }) {
  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <StatTile label="CA net" value={formatXof(kpis.revenue_net_xof)} hint="Commandes payées, remboursements déduits" />
      <StatTile label="Commandes payées" value={kpis.paid_orders} hint={`${kpis.confirmed_orders} confirmée${kpis.confirmed_orders > 1 ? 's' : ''} au total`} />
      <StatTile label="Panier moyen" value={formatXof(kpis.average_basket_xof)} hint="CA net / commandes payées" />
      <StatTile label="Livraisons réussies" value={percent(kpis.delivery_success_rate)} hint={`${kpis.deliveries_delivered} livrée(s) · ${kpis.deliveries_failed} échec(s)`} />
      <StatTile label="Préparation moyenne" value={kpis.avg_prep_minutes === null ? '—' : `${kpis.avg_prep_minutes} min`} hint="Prête − acceptée" />
      <StatTile label="Taux de remboursement" value={percent(kpis.refund_rate)} hint={`${kpis.cancelled_orders} annulée(s) · ${kpis.rejected_orders} refusée(s)`} />
      <StatTile label="Abandon de panier" value={percent(kpis.cart_abandonment_rate)} hint="Paniers non convertis / paniers remplis" />
      <StatTile label="Taux de conversion" value="—" hint="Non mesuré : outil d'analytics à valider" />
    </div>
  )
}
