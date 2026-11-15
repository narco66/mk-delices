import { formatXof } from '@/lib/format'
import { ORDER_STATUS_LABELS } from '../orders/labels'
import type { StatsSummary } from './types'

export function statusItems(breakdown: StatsSummary['status_breakdown']) {
  return breakdown.map((s) => ({ key: s.status, label: ORDER_STATUS_LABELS[s.status] ?? s.status, value: s.count }))
}

export function topProductItems(top: StatsSummary['top_products']) {
  return top.map((p) => ({ key: p.product_id, label: p.name, value: p.quantity, detail: formatXof(p.revenue_xof) }))
}
