const TZ = 'Africa/Dakar'

const xof = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })

/** Montant XOF entier, sans décimales : « 12 500 FCFA ». */
export function formatXof(amount: number | null | undefined): string {
  if (amount === null || amount === undefined) return '—'
  return `${xof.format(amount)}\u202FFCFA`
}

export function formatDateTime(iso: string | null | undefined, opts: Intl.DateTimeFormatOptions = {}): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, dateStyle: 'medium', timeStyle: 'short', ...opts }).format(new Date(iso))
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(iso))
}

/** Date d'un document (facture, avoir) : toujours avec l'année, « 15 nov. 2026 ». Audit M14. */
export function formatDocumentDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, dateStyle: 'medium' }).format(new Date(iso))
}

export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('fr-FR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' }).format(new Date(iso)).replace(':', 'h')
}

export function formatSlot(start: string | null | undefined, end: string | null | undefined): string {
  if (!start) return 'Créneau à définir'
  return `${formatDate(start)} · ${formatTime(start)}–${formatTime(end)}`
}

/** Date locale Dakar AAAA-MM-JJ (UTC+0). */
export function todayDakar(offsetDays = 0): string {
  const d = new Date(Date.now() + offsetDays * 86_400_000)
  return new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(d)
}

export function percent(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return `${(value * 100).toLocaleString('fr-FR', { maximumFractionDigits: 1 })}\u202F%`
}

export function relativeMinutes(minutes: number | null): string {
  if (minutes === null) return ''
  const abs = Math.abs(minutes)
  const label = abs >= 60 ? `${Math.floor(abs / 60)} h ${String(abs % 60).padStart(2, '0')}` : `${abs} min`
  return minutes >= 0 ? `dans ${label}` : `en retard de ${label}`
}

export const ALLERGEN_LABELS: Record<string, string> = {
  lait: 'Lait',
  'œufs': 'Œufs',
  oeufs: 'Œufs',
  gluten: 'Gluten',
  'fruits à coque': 'Fruits à coque',
  arachides: 'Arachides',
  soja: 'Soja',
  sésame: 'Sésame',
}

export function allergenLabel(a: string): string {
  return ALLERGEN_LABELS[a] ?? a.charAt(0).toUpperCase() + a.slice(1)
}

export const PAYMENT_LABELS: Record<string, string> = {
  wave: 'Wave',
  orange_money: 'Orange Money',
  card: 'Carte bancaire',
  cash_on_delivery: 'Paiement à la livraison',
  simulator: 'Paiement simulé (test)',
}

export const CHANNEL_LABELS: Record<string, string> = { web: 'Site', whatsapp: 'WhatsApp', phone: 'Téléphone', backoffice: 'Back-office' }
