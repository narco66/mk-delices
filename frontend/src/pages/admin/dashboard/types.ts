import type { AdminOrder } from '@/types/api'

export interface Kpis {
  revenue_net_xof: number
  paid_orders: number
  confirmed_orders: number
  average_basket_xof: number | null
  delivery_success_rate: number | null
  deliveries_delivered: number
  deliveries_failed: number
  avg_prep_minutes: number | null
  refund_rate: number | null
  cancelled_orders: number
  rejected_orders: number
  cart_abandonment_rate: number | null
  conversion_rate: number | null
}

export interface StatsSummary {
  period: { from: string; to: string; timezone: string }
  kpis: Kpis
  timeseries: { day: string; revenue_net_xof: number; orders: number }[]
  top_products: { product_id: string; name: string; quantity: number; revenue_xof: number }[]
  status_breakdown: { status: string; count: number }[]
  by_zone: {
    zone_id: string | null
    zone_name: string
    orders: number
    paid_orders: number
    revenue_net_xof: number
    delivery_fees_xof: number
    positioned: number
    declared: number
    delivered: number
    failed: number
    late: number
    avg_trip_minutes: number | null
  }[]
}

export interface Operations {
  to_confirm: number
  whatsapp_drafts: number
  in_preparation: number
  ready_to_assign: number
  deliveries_in_progress: number
  deliveries_failed: number
  payments_pending: number
  refunds_pending: number
}

export interface DashboardData {
  operations: Operations
  last_30_days?: StatsSummary
  recent_orders?: AdminOrder[]
}
