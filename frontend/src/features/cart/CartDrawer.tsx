import { ShoppingBag, Trash2 } from 'lucide-react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { DessertArt } from '@/components/brand/Brand'
import { ButtonLink } from '@/components/ui/Button'
import { Modal, Price, QuantityStepper } from '@/components/ui/Display'
import { EmptyState, errorMessage, Notice } from '@/components/ui/Feedback'
import { formatXof } from '@/lib/format'
import { useCart } from './CartContext'

export function CartDrawer() {
  const { cart, drawerOpen, setDrawerOpen, update, remove, isMutating, count } = useCart()
  const lines = count > 0 ? (cart?.lines ?? []) : []

  const safe = (fn: () => Promise<void>) => fn().catch((e) => toast.error(errorMessage(e)))

  return (
    <Modal
      open={drawerOpen}
      onOpenChange={setDrawerOpen}
      side="right"
      title="Votre panier"
      description={lines.length ? `${count} article${count > 1 ? 's' : ''}` : undefined}
      footer={
        lines.length > 0 && (
          <div className="space-y-3">
            <div className="flex justify-between text-sm">
              <span>Sous-total</span>
              <Price amount={cart?.subtotal_xof} />
            </div>
            {(cart?.discount_xof ?? 0) > 0 && (
              <div className="flex justify-between text-sm text-success-600">
                <span>Remise</span>
                <span>−{formatXof(cart?.discount_xof)}</span>
              </div>
            )}
            <p className="text-xs text-cocoa-500">Frais de livraison calculés selon votre zone à l'étape suivante.</p>
            <ButtonLink to="/commander" className="w-full" size="lg" onClick={() => setDrawerOpen(false)}>
              Commander
            </ButtonLink>
            <Link to="/panier" onClick={() => setDrawerOpen(false)} className="block text-center text-sm font-medium text-cocoa-700 underline-offset-2 hover:underline">
              Voir le panier détaillé
            </Link>
          </div>
        )
      }
    >
      {lines.length === 0 ? (
        <EmptyState
          icon={<ShoppingBag className="size-6" />}
          title="Votre panier est vide"
          description="Laissez-vous tenter par nos tiramisus faits maison."
          action={
            <ButtonLink to="/nos-tiramisus" onClick={() => setDrawerOpen(false)}>
              Découvrir les tiramisus
            </ButtonLink>
          }
        />
      ) : (
        <ul className="divide-y divide-cocoa-100/70">
          {cart?.issues.map((issue) => (
            <Notice key={issue.code + issue.message} tone="warning" className="mb-3">
              {issue.message}
            </Notice>
          ))}
          {lines.map((line) => (
            <li key={line.item_id} className="flex gap-3 py-4">
              <DessertArt seed={line.product_slug} className="size-20 shrink-0 rounded-[var(--radius-control)]" label={false} />
              <div className="min-w-0 flex-1">
                <Link to={`/produits/${line.product_slug}`} onClick={() => setDrawerOpen(false)} className="font-medium hover:underline">
                  {line.product_name}
                </Link>
                <p className="text-sm text-cocoa-500">{line.variant_label}</p>
                {!line.available && <p className="text-sm font-medium text-error-600">Plus disponible</p>}
                <div className="mt-2 flex items-center justify-between gap-2">
                  <QuantityStepper size="sm" label={`Quantité ${line.product_name}`} value={line.quantity} disabled={isMutating} onChange={(q) => safe(() => update(line.item_id, q))} />
                  <Price amount={line.line_total_xof} />
                </div>
              </div>
              <button type="button" onClick={() => safe(() => remove(line.item_id))} className="grid size-10 shrink-0 place-items-center rounded-full text-cocoa-300 hover:bg-error-50 hover:text-error-600" aria-label={`Retirer ${line.product_name}`}>
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}
