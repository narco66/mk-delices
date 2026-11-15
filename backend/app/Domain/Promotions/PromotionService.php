<?php

namespace App\Domain\Promotions;

use App\Domain\Shared\DomainException;
use App\Models\Promotion;

/**
 * Calcul de remise. Règle LOY-011 : un seul code promotionnel par commande (pas de cumul).
 */
class PromotionService
{
    /** Retourne la promotion applicable ou lève une erreur explicite. */
    public function findApplicable(string $code, int $subtotal, bool $lock = false): Promotion
    {
        $query = Promotion::query()->where('code', strtoupper(trim($code)));
        if ($lock) {
            $query->lockForUpdate();
        }
        $promotion = $query->first();
        if (! $promotion || ! $promotion->active) {
            throw DomainException::unprocessable('promotion_invalid', 'Ce code promotionnel n\'est pas valide.');
        }
        $now = now();
        if (($promotion->starts_at && $promotion->starts_at->gt($now)) || ($promotion->ends_at && $promotion->ends_at->lt($now))) {
            throw DomainException::unprocessable('promotion_expired', 'Ce code promotionnel n\'est pas valable à cette date.');
        }
        if ($promotion->usage_limit !== null && $promotion->used_count >= $promotion->usage_limit) {
            throw DomainException::unprocessable('promotion_exhausted', 'Ce code promotionnel a atteint sa limite d\'utilisation.');
        }
        if ($promotion->min_subtotal_xof !== null && $subtotal < $promotion->min_subtotal_xof) {
            throw DomainException::unprocessable('promotion_min_subtotal', 'Le montant minimum pour ce code n\'est pas atteint.', ['min_subtotal_xof' => $promotion->min_subtotal_xof]);
        }

        return $promotion;
    }

    /** discount = min(remise éligible, plafond, sous-total) — entier XOF (BR-002). */
    public function discountFor(Promotion $promotion, int $subtotal): int
    {
        $discount = $promotion->discount_type === 'percent'
            ? intdiv($subtotal * $promotion->discount_value, 100)
            : $promotion->discount_value;
        if ($promotion->max_discount_xof !== null) {
            $discount = min($discount, $promotion->max_discount_xof);
        }

        return max(0, min($discount, $subtotal));
    }
}
