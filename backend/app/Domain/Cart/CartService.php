<?php

namespace App\Domain\Cart;

use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Panier invité/client. Le panier n'enregistre que variantes et quantités :
 * aucun prix n'est jamais accepté depuis le client (BR-002).
 */
class CartService
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array{cart: Cart, token: string} */
    public function create(?string $customerId = null): array
    {
        $token = Str::random(48);
        $cart = Cart::query()->create([
            'customer_id' => $customerId,
            'guest_token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours((int) $this->settings->get('ordering.cart_ttl_hours', 72)),
        ]);

        return ['cart' => $cart, 'token' => $token];
    }

    /** Résout un panier à partir de son identifiant et de son jeton secret. */
    public function resolve(string $cartId, ?string $token): Cart
    {
        if (! Str::isUuid($cartId) || $token === null || $token === '') {
            throw new DomainException('cart_not_found', 'Panier introuvable ou expiré.', 404);
        }
        $cart = Cart::query()->find($cartId);
        if (! $cart || ! hash_equals($cart->guest_token_hash, hash('sha256', $token))) {
            throw new DomainException('cart_not_found', 'Panier introuvable ou expiré.', 404);
        }
        if ($cart->isExpired()) {
            throw new DomainException('cart_expired', 'Ce panier a expiré, veuillez en créer un nouveau.', 410);
        }

        return $cart;
    }

    public function setItem(Cart $cart, string $variantId, int $quantity, bool $increment = false): Cart
    {
        $this->assertMutable($cart);
        $variant = ProductVariant::query()->with('product.category')->find($variantId);
        if (! $variant || ! $variant->isSellable()) {
            throw DomainException::unprocessable('variant_unavailable', 'Ce produit n\'est pas disponible à la commande.', ['variant_id' => $variantId]);
        }

        $item = $cart->items()->where('variant_id', $variantId)->first();
        $newQty = $increment && $item ? $item->quantity + $quantity : $quantity;
        $this->assertQuantity($variant, $newQty);

        if ($item) {
            $item->update(['quantity' => $newQty]);
        } else {
            $cart->items()->create(['variant_id' => $variantId, 'quantity' => $newQty]);
        }
        $this->touch($cart);

        return $cart->fresh('items');
    }

    public function updateItem(Cart $cart, CartItem $item, int $quantity): Cart
    {
        $this->assertMutable($cart);
        if ($quantity === 0) {
            $item->delete();
        } else {
            $this->assertQuantity($item->variant, $quantity);
            $item->update(['quantity' => $quantity]);
        }
        $this->touch($cart);

        return $cart->fresh('items');
    }

    public function setPromotionCode(Cart $cart, ?string $code): Cart
    {
        $this->assertMutable($cart);
        $cart->update(['promotion_code' => $code ? strtoupper(trim($code)) : null]);

        return $cart;
    }

    private function assertQuantity(ProductVariant $variant, int $quantity): void
    {
        $max = min(
            (int) $this->settings->get('ordering.max_qty_per_line', 20),
            $variant->max_per_order ?? PHP_INT_MAX,
        );
        if ($quantity < 1 || $quantity > $max) {
            throw DomainException::unprocessable('invalid_quantity', "La quantité doit être comprise entre 1 et {$max}.", ['max' => $max]);
        }
        if ($variant->stock_qty !== null && $quantity > $variant->stock_qty) {
            throw DomainException::conflict('insufficient_stock', 'Quantité demandée supérieure au stock disponible.', ['available' => $variant->stock_qty]);
        }
    }

    private function assertMutable(Cart $cart): void
    {
        if ($cart->converted_order_id !== null) {
            $order = $cart->convertedOrder;
            if ($order && $order->status->value !== 'draft') {
                throw DomainException::conflict('cart_converted', 'Ce panier a déjà été transformé en commande.', ['reference' => $order->reference]);
            }
        }
    }

    private function touch(Cart $cart): void
    {
        $cart->update(['expires_at' => now()->addHours((int) $this->settings->get('ordering.cart_ttl_hours', 72))]);
    }
}
