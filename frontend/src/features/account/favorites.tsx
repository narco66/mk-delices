import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Heart } from 'lucide-react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { errorMessage } from '@/components/ui/Feedback'
import { useAuth } from '@/features/auth/AuthContext'
import { api } from '@/lib/api'
import { cn } from '@/lib/cn'
import type { Product } from '@/types/api'
import { FAVORITES_KEY as KEY, useFavorites } from './useFavorites'

/** Cœur « ajouter aux favoris » : un visiteur est invité à se connecter. */
export function FavoriteButton({ product, className }: { product: Pick<Product, 'id' | 'name'>; className?: string }) {
  const { me } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { ids } = useFavorites()
  const active = ids.has(product.id)
  const toggle = useMutation({
    mutationFn: () => (active ? api.delete(`/account/favorites/${product.id}`) : api.post('/account/favorites', { product_id: product.id })),
    onSuccess: () => {
      toast.success(active ? 'Retiré de vos favoris' : 'Ajouté à vos favoris')
      queryClient.invalidateQueries({ queryKey: KEY })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  // Le personnel et les livreurs n'ont pas de favoris.
  if (me && !me.customer) return null

  return (
    <button
      type="button"
      aria-pressed={active}
      aria-label={active ? `Retirer ${product.name} de mes favoris` : `Enregistrer ${product.name} dans mes favoris`}
      disabled={toggle.isPending}
      onClick={(e) => {
        e.preventDefault()
        e.stopPropagation()
        if (!me) {
          toast.info('Connectez-vous pour enregistrer vos favoris.')
          navigate(`/connexion?redirect=${encodeURIComponent(window.location.pathname)}`)
          return
        }
        toggle.mutate()
      }}
      className={cn('grid size-10 place-items-center rounded-full bg-white/90 text-cocoa-700 shadow-soft transition hover:bg-white hover:text-raspberry-600 disabled:opacity-60', className)}
    >
      <Heart className={cn('size-5', active && 'fill-raspberry-500 text-raspberry-500')} aria-hidden />
    </button>
  )
}
