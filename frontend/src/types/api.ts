/** Types partagés du contrat API v1 (miroir des Resources Laravel). */

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  links?: unknown
}

export interface Variant {
  id: string
  sku: string
  label: string
  flavor: string | null
  size_label: string | null
  serves: number | null
  price_xof: number
  compare_at_price_xof: number | null
  available: boolean
  prep_minutes: number
  max_per_order: number | null
  stock_qty?: number | null
  available_flag?: boolean
  position?: number
  archived?: boolean
}

export interface ProductImage {
  id: string
  url: string
  srcset: string | null
  alt: string
  width: number | null
  height: number | null
  is_illustrative: boolean
}

export interface Product {
  id: string
  slug: string
  name: string
  tagline: string | null
  category?: { id: string; name: string; slug: string }
  price_from_xof: number | null
  is_featured: boolean
  is_new: boolean
  is_signature: boolean
  available: boolean | null
  allergens: string[]
  requires_cold_chain: boolean
  is_demo: boolean
  images?: ProductImage[]
  variants?: Variant[]
  description?: string | null
  ingredients?: string | null
  storage_instructions?: string | null
  shelf_life_hours?: number | null
  seo_title?: string | null
  seo_description?: string | null
  related?: Product[]
  rating?: { average: number | null; count: number; items: { author: string; stars: number; comment: string | null; date: string }[] }
  // admin
  status?: 'draft' | 'published' | 'inactive' | 'archived'
  category_id?: string
  position?: number
  archived?: boolean
  related_ids?: string[]
  updated_at?: string
}

export interface Category {
  id: string
  name: string
  slug: string
  description: string | null
  products_count?: number
  active?: boolean
  position?: number
}

export interface Issue {
  code: string
  message: string
  [key: string]: unknown
}

export interface QuoteLine {
  item_id: string
  variant_id: string
  product_name: string
  product_slug: string
  variant_label: string
  quantity: number
  unit_price_xof: number
  line_total_xof: number
  available: boolean
  allergens: string[]
}

export interface Slot {
  start_at: string
  end_at: string
  remaining: number
  label: string
}

export interface Quote {
  lines: QuoteLine[]
  subtotal_xof: number
  discount_xof: number
  delivery_fee_xof: number
  total_xof: number
  fulfillment_type?: 'delivery' | 'pickup'
  zone?: { id: string; name: string } | null
  /** « position » : zone calculée par le serveur depuis la position ; « declared » : zone choisie. */
  zone_source?: 'position' | 'declared'
  promotion?: { code: string; name: string } | null
  lead_minutes?: number
  issues: Issue[]
  valid?: boolean
  slots?: Slot[]
}

export interface CartState extends Quote {
  cart_id?: string
  cart_token?: string
  promotion_code: string | null
  expires_at: string
  converted_order: { reference: string; status: string } | null
  item_count: number
}

export interface Zone {
  id: string
  name: string
  slug: string
  description: string | null
  districts: string | null
  delivery_fee_xof: number
  min_order_xof: number | null
  /** Contour GeoJSON (coordonnées [lng, lat]) ; null = zone sans contour (choisie par le client). */
  area?: GeoArea | null
  // admin
  active?: boolean
  partner_fee_xof?: number
  extra_lead_minutes?: number
  position?: number
  partners_count?: number
  is_demo?: boolean
}

export interface PaymentMethod {
  code: string
  label: string
  online: boolean
  test_only: boolean
}

export interface PublicConfig {
  settings: Record<string, unknown>
  payment_methods: PaymentMethod[]
  currency: 'XOF'
  timezone: string
  maps?: MapsConfig
}

export interface MapsConfig {
  /** Fond de carte commercial (null : pas de carte, seulement « Utiliser ma position » et la saisie). */
  tiles_url: string | null
  attribution: string
  geocoding: boolean
  default_center: { lat: number; lng: number; zoom: number }
  bounds: { min_lat: number; max_lat: number; min_lng: number; max_lng: number }
  /** Périmètre desservi (ex. ville de Dakar) ; null = toute la région, zone choisie par le client. */
  service_area?: GeoArea | null
}

export type GeoArea = { type: 'Polygon'; coordinates: number[][][] } | { type: 'MultiPolygon'; coordinates: number[][][][] }

export type LocationSource = 'gps' | 'map' | 'search' | 'saved'

export interface PickedLocation {
  lat: number
  lng: number
  source: LocationSource
  accuracy_m?: number | null
}

export interface GeoPlace {
  label: string
  lat: number | null
  lng: number | null
  district: string | null
  street: string | null
}

export interface GeoResolution {
  point: { lat: number; lng: number }
  zone: { id: string; name: string; delivery_fee_xof: number; min_order_xof: number | null } | null
  zone_source: 'position' | 'declared'
  zones_mapped: boolean
  /** false : position hors du périmètre desservi (ex. hors de la ville de Dakar). */
  in_service_area?: boolean
  deliverable: boolean
  suggestion: GeoPlace | null
  geocoding: 'ok' | 'unavailable' | 'disabled'
}

export interface TimelineStep {
  status: string
  label: string
  done: boolean
}

export interface PublicOrder {
  reference: string
  channel: string
  status: string
  status_label: string
  payment_status: string
  payment_status_label: string
  payment_method: string | null
  fulfillment_type: string
  first_name: string | null
  zone_name: string | null
  district: string | null
  slot_start_at: string | null
  slot_end_at: string | null
  subtotal_xof: number
  discount_xof: number
  delivery_fee_xof: number
  total_xof: number
  amount_due_xof: number
  currency: string
  items: { product_id: string; product_name: string; variant_label: string; qty: number; unit_price_xof: number; line_total_xof: number; allergens: string[] }[]
  timeline: TimelineStep[]
  can_pay: boolean
  can_cancel: boolean
  can_review: boolean
  invoices: InvoiceSummary[]
  /** Code de remise à donner au livreur (tant que la commande n'est pas remise). */
  delivery_code?: string | null
  created_at: string
  tracking_token?: string
}

/** Facture ou avoir (documents immuables, numérotation continue). */
export interface InvoiceSummary {
  id: string
  type: 'invoice' | 'credit_note'
  type_label: string
  number: string
  issued_at: string
  vat_applicable: boolean
  total_ht_xof: number
  total_vat_xof: number
  total_ttc_xof: number
  is_test: boolean
}

export interface InvoiceRow extends InvoiceSummary {
  order_id: string
  order_reference: string | null
  buyer_name: string | null
}

export interface Me {
  id: string
  name: string
  email: string | null
  phone: string | null
  roles: string[]
  permissions: string[]
  is_staff: boolean
  is_courier: boolean
  customer: { id: string; first_name: string; last_name: string | null; phone: string; marketing_consent: boolean } | null
}

export interface OrderEvent {
  id: string
  type: string
  from_status: string | null
  to_status: string | null
  reason: string | null
  metadata: Record<string, unknown> | null
  actor: { id: string; name: string } | null
  actor_label: string | null
  request_id: string | null
  occurred_at: string
}

export interface Payment {
  id: string
  order_id: string
  order_reference?: string
  provider: string
  provider_reference: string | null
  status: string
  amount_xof: number
  currency: string
  provider_status: string | null
  failure_reason: string | null
  is_simulated: boolean
  is_manual: boolean
  verified_at: string | null
  expires_at: string | null
  last_checked_at: string | null
  created_at: string
  events?: { provider_event_id: string; event_type: string; processing_status: string; received_at: string; processed_at: string | null }[]
}

export interface Refund {
  id: string
  order_id: string
  order_reference?: string
  payment_id: string
  amount_xof: number
  status: string
  /** « sale » : réduction de la vente (avoir) ; « overpayment » : restitution d'un trop-perçu */
  kind?: 'sale' | 'overpayment'
  /** Pourquoi le remboursement n'a pas été exécuté automatiquement (action manuelle attendue) */
  execution_note?: string | null
  reason: string
  provider_ref: string | null
  requested_by?: string | null
  requested_by_id: string | null
  approved_by?: string | null
  approved_at: string | null
  completed_at: string | null
  created_at: string
}

export interface Assignment {
  id: string
  order_id: string
  order_reference?: string
  status: string
  status_label: string
  partner?: { id: string; name: string; transport_mode: string }
  partner_fee_xof: number
  cash_to_collect_xof: number
  cash_collected_xof: number | null
  assigned_at: string | null
  accepted_at: string | null
  refused_at: string | null
  refusal_reason: string | null
  picked_up_at: string | null
  packaging_checked: boolean
  delivered_at: string | null
  proof_type: string | null
  proof_reference: string | null
  failed_at: string | null
  incident_reason: string | null
  incident_note: string | null
  product_condition: string | null
  contact_attempted: boolean | null
  closed_at: string | null
  cancel_reason: string | null
  settled_at: string | null
}

export interface AdminOrder {
  id: string
  reference: string
  channel: string
  status: string
  status_label: string
  payment_status: string
  payment_status_label: string
  payment_method: string | null
  fulfillment_type: string
  subtotal_xof: number
  discount_xof: number
  delivery_fee_xof: number
  total_xof: number
  paid_xof: number
  refunded_xof: number
  currency: string
  promotion_code: string | null
  customer: { first_name: string | null; last_name: string | null; phone: string | null; email: string | null }
  customer_id: string | null
  zone?: { id: string; name: string } | null
  address: {
    zone_name?: string | null
    district?: string | null
    street?: string | null
    landmark?: string | null
    lat?: number | null
    lng?: number | null
    location_source?: 'gps' | 'map' | 'search' | 'saved' | 'staff' | null
    accuracy_m?: number | null
    /** position : zone calculée depuis la position ; declared : choisie par le client (à vérifier) ; staff : équipe. */
    zone_source?: 'position' | 'declared' | 'staff'
  } | null
  slot_start_at: string | null
  slot_end_at: string | null
  customer_notes: string | null
  internal_notes?: string | null
  /** Code de remise (commercial uniquement) : à dicter au client d'une commande téléphone. */
  delivery_code?: string | null
  details_locked: boolean
  is_test: boolean
  version: number
  timestamps: Record<string, string | null>
  items?: { id: string; product_id: string; variant_id: string; product_name: string; variant_label: string; sku: string; unit_price_xof: number; qty: number; line_total_xof: number; allergens: string[]; storage: string | null; prep_minutes: number }[]
  events?: OrderEvent[]
  payments?: Payment[]
  refunds?: Refund[]
  assignments?: Assignment[]
  invoices?: InvoiceSummary[]
  can_issue_invoice?: boolean
  active_assignment?: Assignment | null
  last_incident?: Assignment | null
}

export interface Mission {
  id: string
  status: string
  status_label: string
  order_reference: string
  slot_start_at: string | null
  slot_end_at: string | null
  zone_name: string | null
  district: string | null
  recipient_first_name: string | null
  recipient_phone: string | null
  street: string | null
  landmark: string | null
  lat: number | null
  lng: number | null
  customer_notes: string | null
  items: { name: string; variant: string; qty: number; storage: string | null }[]
  requires_cold_chain: boolean
  cash_to_collect_xof: number
  /** Essais de code de remise restants (5 au départ). */
  code_attempts_left: number
  partner_fee_xof: number
  assigned_at: string | null
  accepted_at: string | null
  picked_up_at: string | null
  delivered_at: string | null
  failed_at: string | null
  proof_type: string | null
  incident_reason: string | null
  settled_at: string | null
  available_actions: string[]
}
