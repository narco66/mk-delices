<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\CartService;
use App\Domain\Cart\QuoteService;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panier invité : identifié par X-Cart-Id + X-Cart-Token (secret haché côté serveur).
 * Aucun prix n'est accepté en entrée (BR-002).
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $carts, private readonly QuoteService $quotes) {}

    public function store(Request $request): JsonResponse
    {
        $created = $this->carts->create($request->user()?->customer?->id);

        return response()->json(['data' => ['cart_id' => $created['cart']->id, 'cart_token' => $created['token']] + $this->payload($created['cart'])], 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->cart($request))]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $cart = $this->carts->setItem($this->cart($request), $data['variant_id'], (int) $data['quantity'], increment: true);

        return response()->json(['data' => $this->payload($cart)]);
    }

    public function updateItem(Request $request, string $itemId): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:100']]);
        $cart = $this->cart($request);
        $item = $cart->items()->findOrFail($itemId);

        return response()->json(['data' => $this->payload($this->carts->updateItem($cart, $item, (int) $data['quantity']))]);
    }

    public function removeItem(Request $request, string $itemId): JsonResponse
    {
        $cart = $this->cart($request);
        $item = $cart->items()->findOrFail($itemId);

        return response()->json(['data' => $this->payload($this->carts->updateItem($cart, $item, 0))]);
    }

    public function promotion(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_]+$/']]);
        $cart = $this->carts->setPromotionCode($this->cart($request), $data['code'] ?? null);

        return response()->json(['data' => $this->payload($cart)]);
    }

    /**
     * « Recommander » : remet les articles d'une commande passée dans le panier, aux prix actuels.
     * Panier courant (X-Cart-Id/X-Cart-Token) complété s'il est valide, sinon nouveau panier (jeton renvoyé).
     * Les articles devenus indisponibles sont signalés au lieu de bloquer le reste.
     */
    public function reorder(Request $request, string $ref): JsonResponse
    {
        $customer = $request->user()->customer ?? throw new DomainException('no_customer_profile', 'Aucun profil client associé à ce compte.', 404);
        $order = $customer->orders()->where('reference', $ref)->where('status', '!=', 'draft')->with('items')->first()
            ?? throw new DomainException('order_not_found', 'Commande introuvable.', 404);

        $created = null;
        try {
            $cart = $this->cart($request);
            if ($cart->converted_order_id !== null && $cart->convertedOrder?->status->value !== 'draft') {
                throw new DomainException('cart_converted', 'Panier déjà commandé.', 409);
            }
        } catch (DomainException) {
            $created = $this->carts->create($customer->id);
            $cart = $created['cart'];
        }

        $skipped = [];
        foreach ($order->items as $item) {
            try {
                $this->carts->setItem($cart, $item->variant_id, $item->qty, increment: true);
            } catch (DomainException $e) {
                $skipped[] = ['name' => trim($item->product_name_snapshot.' — '.$item->variant_label_snapshot, ' —'), 'quantity' => $item->qty, 'reason' => $e->getMessage()];
            }
        }
        if (count($skipped) === $order->items->count()) {
            throw DomainException::unprocessable('reorder_unavailable', 'Aucun article de cette commande n\'est disponible aujourd\'hui.', ['skipped' => $skipped]);
        }
        $cart->refresh();

        return response()->json(['data' => ($created ? ['cart_id' => $cart->id, 'cart_token' => $created['token']] : ['cart_id' => $cart->id])
            + $this->payload($cart) + ['skipped' => $skipped]]);
    }

    private function cart(Request $request): Cart
    {
        return $this->carts->resolve((string) $request->header('X-Cart-Id'), $request->header('X-Cart-Token'));
    }

    private function payload(Cart $cart): array
    {
        $order = $cart->converted_order_id ? $cart->convertedOrder : null;
        $base = [
            'promotion_code' => $cart->promotion_code,
            'expires_at' => $cart->expires_at->toIso8601ZuluString(),
            'converted_order' => $order ? ['reference' => $order->reference, 'status' => $order->status->value] : null,
        ];
        if (! $cart->items()->exists()) {
            return $base + ['lines' => [], 'subtotal_xof' => 0, 'discount_xof' => 0, 'delivery_fee_xof' => 0, 'total_xof' => 0, 'item_count' => 0, 'issues' => []];
        }
        $quote = $this->quotes->quote($cart, null)->toArray();

        return $base + $quote + ['item_count' => array_sum(array_column($quote['lines'], 'quantity'))];
    }
}
