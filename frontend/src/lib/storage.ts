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

export const orderStorage = {
  all: (): StoredOrder[] => safeGet<StoredOrder[]>(ORDERS_KEY) ?? [],
  get: (reference: string) => orderStorage.all().find((o) => o.reference === reference) ?? null,
  save(reference: string, token: string) {
    const others = orderStorage.all().filter((o) => o.reference !== reference)
    safeSet(ORDERS_KEY, [{ reference, token, createdAt: new Date().toISOString() }, ...others].slice(0, 20))
  },
}

export function trackingUrl(reference: string, token: string): string {
  return `/suivi/${reference}?t=${encodeURIComponent(token)}`
}
