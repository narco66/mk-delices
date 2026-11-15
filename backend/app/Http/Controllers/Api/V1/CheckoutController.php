<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cart\CartService;
use App\Domain\Cart\QuoteService;
use App\Domain\Orders\CheckoutService;
use App\Domain\Orders\SlotService;
use App\Domain\Orders\WhatsAppMessageBuilder;
use App\Domain\Shared\Idempotency;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\PublicOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly QuoteService $quotes,
        private readonly SlotService $slots,
        private readonly CheckoutService $checkout,
    ) {}

    /** Devis serveur : montants, zone, problèmes bloquants et créneaux disponibles. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'zone_id' => ['nullable', 'uuid'],
            'fulfillment_type' => ['nullable', 'in:delivery,pickup'],
            'promotion_code' => ['nullable', 'string', 'max:40'],
        ]);
        $cart = $this->carts->resolve((string) $request->header('X-Cart-Id'), $request->header('X-Cart-Token'));
        $quote = $this->quotes->quote($cart, $data['zone_id'] ?? null, $data['fulfillment_type'] ?? 'delivery', $data['promotion_code'] ?? null);

        return response()->json(['data' => $quote->toArray() + ['slots' => $this->slots->available($quote->leadMinutes)]]);
    }

    public function placeOrder(PlaceOrderRequest $request, Idempotency $idempotency): JsonResponse
    {
        $cart = $this->carts->resolve((string) $request->header('X-Cart-Id'), $request->header('X-Cart-Token'));
        $payload = $request->validated() + ['cart_id' => $cart->id];

        $result = $idempotency->run('checkout.orders', $request->header('Idempotency-Key'), $payload, function () use ($cart, $request) {
            $placed = $this->checkout->placeOrder($cart, $request->validated(), $request->user());

            return ['status' => 201, 'body' => ['data' => (new PublicOrderResource($placed['order']->load('items')))->resolve($request) + ['tracking_token' => $placed['tracking_token']]]];
        });

        return response()->json($result['body'], $result['status'], $result['replayed'] ? ['Idempotent-Replayed' => 'true'] : []);
    }

    /** Brouillon WhatsApp : référence stable + message prérempli. N'est ni une commande confirmée ni un paiement. */
    public function whatsappDraft(Request $request, WhatsAppMessageBuilder $whatsapp): JsonResponse
    {
        $data = $request->validate([
            'zone_id' => ['nullable', 'uuid'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'promotion_code' => ['nullable', 'string', 'max:40'],
        ]);
        $cart = $this->carts->resolve((string) $request->header('X-Cart-Id'), $request->header('X-Cart-Token'));
        $result = $this->checkout->saveWhatsAppDraft($cart, $data, $request->user());
        $order = $result['order']->load('items', 'zone');

        return response()->json(['data' => [
            'reference' => $order->reference,
            'status' => $order->status->value,
            'is_draft' => $order->status->value === 'draft',
            'total_xof' => $order->total_xof,
            'message' => $whatsapp->message($order),
            'whatsapp_url' => $whatsapp->url($order),
            'tracking_token' => $result['tracking_token'],
            'notice' => 'Ce message ne vaut ni confirmation ni paiement : MK Délices confirmera votre commande.',
        ]], $result['created'] ? 201 : 200);
    }
}
