import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/Button'

export interface PageMeta {
  current_page: number
  last_page: number
  total: number
  per_page?: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: PageMeta
}

export function Pagination({ meta, onPage }: { meta?: PageMeta; onPage: (page: number) => void }) {
  if (!meta || meta.last_page <= 1) return null
  return (
    <nav aria-label="Pagination" className="mt-6 flex items-center justify-between gap-3">
      <p className="text-sm text-cocoa-500">
        Page {meta.current_page} sur {meta.last_page} · {meta.total} résultat{meta.total > 1 ? 's' : ''}
      </p>
      <div className="flex gap-2">
        <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} icon={<ChevronLeft className="size-4" />}>
          Précédent
        </Button>
        <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
          Suivant
          <ChevronRight className="size-4" />
        </Button>
      </div>
    </nav>
  )
}

export const ORDER_STATUS_LABELS: Record<string, string> = {
  draft: 'Brouillon',
  pending_confirmation: 'À confirmer',
  accepted: 'Acceptée',
  preparing: 'En préparation',
  ready: 'Prête',
  assigned: 'Confiée au livreur',
  picked_up: 'En livraison',
  delivered: 'Livrée',
  delivery_failed: 'Échec de livraison',
  cancelled: 'Annulée',
  rejected: 'Refusée',
}

export const PAYMENT_STATUS_LABELS: Record<string, string> = {
  unpaid: 'Non payée',
  pending: 'En vérification',
  paid: 'Payée',
  failed: 'Échec',
  partially_refunded: 'Remb. partiel',
  refunded: 'Remboursée',
}

export const PAYMENT_ATTEMPT_LABELS: Record<string, string> = {
  initiated: 'Initiée',
  pending: 'En attente',
  succeeded: 'Réussie',
  failed: 'Échouée',
  cancelled: 'Annulée',
  expired: 'Expirée',
}

export const REFUND_STATUS_LABELS: Record<string, string> = {
  requested: 'Demandé',
  approved: 'Approuvé',
  rejected: 'Rejeté',
  succeeded: 'Effectué',
  failed: 'Échoué',
}

export const ACTION_LABELS: Record<string, string> = {
  submit: 'Confirmer le brouillon',
  accept: 'Accepter',
  reject: 'Refuser',
  cancel: 'Annuler',
  start_preparation: 'Démarrer la préparation',
  mark_ready: 'Marquer prête',
  hand_over_pickup: 'Remis au client (retrait)',
}

export const INCIDENT_LABELS: Record<string, string> = {
  client_absent: 'Client absent',
  adresse_introuvable: 'Adresse introuvable',
  client_injoignable: 'Client injoignable',
  refus_client: 'Refus du client',
  produit_endommage: 'Produit endommagé',
  autre: 'Autre',
}

export const CONDITION_LABELS: Record<string, string> = {
  intact: 'Intact',
  abime: 'Abîmé',
  rupture_froid: 'Rupture de la chaîne du froid',
  inconnu: 'Inconnu',
}

export const PROOF_LABELS: Record<string, string> = {
  recipient_confirmation: 'Confirmation du destinataire',
  photo_reference: 'Référence photo',
  code: 'Code de remise',
}

export const TRANSPORT_LABELS: Record<string, string> = { moto: 'Moto', voiture: 'Voiture', velo: 'Vélo', a_pied: 'À pied' }

export function providerLabel(provider: string): string {
  const map: Record<string, string> = {
    wave: 'Wave',
    orange_money: 'Orange Money',
    simulator: 'Simulateur (test)',
    'manual:cash': 'Espèces (manuel)',
    'manual:wave_direct': 'Wave direct (manuel)',
    'manual:orange_money_direct': 'Orange Money direct (manuel)',
    'manual:bank_transfer': 'Virement (manuel)',
    'manual:other': 'Autre (manuel)',
  }
  return map[provider] ?? provider
}

export function chip(active: boolean): string {
  return active
    ? 'rounded-full border border-cocoa-700 bg-cocoa-700 px-3 py-1.5 text-xs font-semibold text-cream-50'
    : 'rounded-full border border-cocoa-100 bg-white px-3 py-1.5 text-xs font-semibold text-cocoa-700 hover:border-cocoa-300'
}
