/**
 * Stockage local limité au strict nécessaire :
 * - identifiant + secret du panier invité (donne accès au seul panier) ;
 * - liens de suivi des commandes invitées passées sur cet appareil.
 * Aucun jeton de session/authentification n'est stocké ici (TRD §5).
 */

const CART_KEY = 'mkd.cart'
const ORDERS_KEY = 'mkd.orders'

export interface StoredCart {
  id: string
  token: string
}

function safeGet<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : null
  } catch {
    return null
  }
}

function safeSet(key: string, value: unknown): void {
  try {
    if (value === null) localStorage.removeItem(key)
    else localStorage.setItem(key, JSON.stringify(value))
  } catch {
    /* navigation privée : on continue sans persistance */
  }
}

export const cartStorage = {
  get: () => safeGet<StoredCart>(CART_KEY),
  set: (cart: StoredCart | null) => safeSet(CART_KEY, cart),
}

export interface StoredOrder {
  reference: string
  token: string
  createdAt: string
}

/** Durée de conservation des liens de suivi sur l'appareil (audit M3), alignée sur la validité côté serveur. */
export const ORDER_TOKEN_TTL_DAYS = 30
const MAX_STORED_ORDERS = 10

function isFresh(order: StoredOrder): boolean {
  const created = Date.parse(order.createdAt)
  return Number.isFinite(created) && Date.now() - created < ORDER_TOKEN_TTL_DAYS * 86_400_000
}

export const orderStorage = {
  /** Commandes mémorisées sur cet appareil, sans celles dont le lien a expiré (purgées au passage). */
  all(): StoredOrder[] {
    const stored = safeGet<StoredOrder[]>(ORDERS_KEY) ?? []
    const fresh = stored.filter(isFresh)
    if (fresh.length !== stored.length) safeSet(ORDERS_KEY, fresh)
    return fresh
  },
  get: (reference: string) => orderStorage.all().find((o) => o.reference === reference) ?? null,
  save(reference: string, token: string) {
    const others = orderStorage.all().filter((o) => o.reference !== reference)
    safeSet(ORDERS_KEY, [{ reference, token, createdAt: new Date().toISOString() }, ...others].slice(0, MAX_STORED_ORDERS))
  },
  /** « Oublier cet appareil » : efface tous les liens de suivi mémorisés (téléphone partagé). */
  clear: () => safeSet(ORDERS_KEY, null),
}

export function trackingUrl(reference: string, token: string): string {
  return `/suivi/${reference}?t=${encodeURIComponent(token)}`
}
