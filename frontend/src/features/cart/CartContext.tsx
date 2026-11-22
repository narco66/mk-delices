import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { toast } from 'sonner'
import { api, ApiError } from '@/lib/api'
import { cartStorage, type StoredCart } from '@/lib/storage'
import type { CartState } from '@/types/api'

interface CartContextValue {
  cart: CartState | null
  stored: StoredCart | null
  isLoading: boolean
  count: number
  drawerOpen: boolean
  setDrawerOpen: (open: boolean) => void
  add: (variantId: string, quantity: number, label?: string) => Promise<void>
  update: (itemId: string, quantity: number) => Promise<void>
  remove: (itemId: string) => Promise<void>
  applyPromotion: (code: string | null) => Promise<void>
  reset: () => void
  /** Panier renvoyé par « Recommander » : nouveau panier (avec jeton) ou panier courant complété. */
  adopt: (data: CartState) => void
  headers: () => Record<string, string>
  isMutating: boolean
}

const CartContext = createContext<CartContextValue | null>(null)

/** Panier serveur : seuls variantes et quantités sont envoyées, les montants viennent toujours de l'API. */
export function CartProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [stored, setStored] = useState<StoredCart | null>(() => cartStorage.get())
  const [drawerOpen, setDrawerOpen] = useState(false)

  const headers = useCallback((): Record<string, string> => (stored ? { 'X-Cart-Id': stored.id, 'X-Cart-Token': stored.token } : {}), [stored])

  const persist = useCallback((value: StoredCart | null) => {
    cartStorage.set(value)
    setStored(value)
  }, [])

  const { data: cart = null, isLoading } = useQuery({
    queryKey: ['cart', stored?.id],
    enabled: !!stored,
    retry: false,
    queryFn: async () => {
      try {
        return (await api.get<{ data: CartState }>('/cart', { headers: headers() })).data
      } catch (error) {
        if (error instanceof ApiError && [404, 410].includes(error.status)) {
          persist(null)
          return null
        }
        throw error
      }
    },
  })

  const ensureCart = useCallback(async (): Promise<StoredCart> => {
    // Un panier déjà transformé en commande n'est plus modifiable : on en ouvre un nouveau.
    if (stored && !(cart?.converted_order && cart.converted_order.status !== 'draft')) return stored
    const created = (await api.post<{ data: CartState }>('/cart')).data
    const next = { id: created.cart_id!, token: created.cart_token! }
    persist(next)
    return next
  }, [stored, cart, persist])

  const mutation = useMutation({
    mutationFn: async (run: (h: Record<string, string>) => Promise<{ data: CartState }>) => {
      const current = await ensureCart()
      return { result: await run({ 'X-Cart-Id': current.id, 'X-Cart-Token': current.token }), id: current.id }
    },
    onSuccess: ({ result, id }) => queryClient.setQueryData(['cart', id], result.data),
  })

  const add = useCallback(
    async (variantId: string, quantity: number, label?: string) => {
      await mutation.mutateAsync((h) => api.post('/cart/items', { variant_id: variantId, quantity }, { headers: h }))
      toast.success(label ? `${label} ajouté au panier` : 'Ajouté au panier', { action: { label: 'Voir le panier', onClick: () => setDrawerOpen(true) } })
    },
    [mutation],
  )

  const update = useCallback(
    async (itemId: string, quantity: number) => {
      await mutation.mutateAsync((h) => api.patch(`/cart/items/${itemId}`, { quantity }, { headers: h }))
    },
    [mutation],
  )

  const remove = useCallback(
    async (itemId: string) => {
      await mutation.mutateAsync((h) => api.delete(`/cart/items/${itemId}`, { headers: h }))
    },
    [mutation],
  )

  const applyPromotion = useCallback(
    async (code: string | null) => {
      await mutation.mutateAsync((h) => api.put('/cart/promotion', { code }, { headers: h }))
    },
    [mutation],
  )

  const adopt = useCallback(
    (data: CartState) => {
      const id = data.cart_id ?? stored?.id
      if (!id) return
      if (data.cart_token) persist({ id, token: data.cart_token })
      queryClient.setQueryData(['cart', id], data)
    },
    [stored, persist, queryClient],
  )

  const reset = useCallback(() => {
    persist(null)
    queryClient.removeQueries({ queryKey: ['cart'] })
  }, [persist, queryClient])

  const value = useMemo<CartContextValue>(
    () => ({
      cart,
      stored,
      isLoading: !!stored && isLoading,
      count: cart?.converted_order && cart.converted_order.status !== 'draft' ? 0 : (cart?.item_count ?? 0),
      drawerOpen,
      setDrawerOpen,
      add,
      update,
      remove,
      applyPromotion,
      reset,
      adopt,
      headers,
      isMutating: mutation.isPending,
    }),
    [cart, stored, isLoading, drawerOpen, add, update, remove, applyPromotion, reset, adopt, headers, mutation.isPending],
  )

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

export function useCart(): CartContextValue {
  const ctx = useContext(CartContext)
  if (!ctx) throw new Error('useCart doit être utilisé dans CartProvider')
  return ctx
}
