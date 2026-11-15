<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderTracking;
use App\Domain\Orders\WhatsAppMessageBuilder;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOrderResource;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accès « propriétaire » à une commande : jeton de suivi secret (X-Tracking-Token)
 * ou compte client propriétaire. Erreurs indistinctes (404).
 */
class OrderTrackingController extends Controller
{
    public function __construct(private readonly OrderTracking $tracking) {}

    public function status(Request $request, string $reference): PublicOrderResource
    {
        return new PublicOrderResource($this->order($request, $reference)->load('items'));
    }

    public function pay(Request $request, string $reference, PaymentService $payments): JsonResponse
    {
        $data = $request->validate(['method' => ['required', 'string', 'max:30']]);
        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9\-_:.]{8,100}$/', $key)) {
            throw DomainException::unprocessable('idempotency_key_required', 'En-tête Idempotency-Key manquant ou invalide.');
        }
        $payment = $payments->initiate($this->order($request, $reference), $data['method'], $key);

        return response()->json(['data' => [
            'payment_id' => $payment->id,
            'status' => $payment->status->value,
            'amount_xof' => $payment->amount_xof,
            'checkout_url' => $payment->checkout_url,
            'expires_at' => $payment->expires_at?->toIso8601ZuluString(),
            'is_simulated' => $payment->is_simulated,
        ]], 201);
    }

    /**
     * Retour depuis le prestataire : ne valide JAMAIS le paiement à lui seul (T-009).
     * Déclenche seulement un rapprochement serveur-à-serveur.
     */
    public function refreshPayment(Request $request, string $reference, PaymentService $payments): PublicOrderResource
    {
        $order = $this->order($request, $reference);
        foreach ($order->payments()->whereIn('status', [PaymentStatus::Initiated->value, PaymentStatus::Pending->value])->get() as $payment) {
            $payments->reconcile($payment);
        }

        return new PublicOrderResource($order->fresh('items'));
    }

    public function cancel(Request $request, string $reference, OrderStateMachine $machine): PublicOrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $order = $machine->cancelByCustomer($this->order($request, $reference), $data['reason']);

        return new PublicOrderResource($order->fresh('items'));
    }

    public function whatsapp(Request $request, string $reference, WhatsAppMessageBuilder $whatsapp): JsonResponse
    {
        $order = $this->order($request, $reference)->load('items', 'zone');

        return response()->json(['data' => ['message' => $whatsapp->message($order), 'whatsapp_url' => $whatsapp->url($order)]]);
    }

    public function review(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'stars' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $order = $this->order($request, $reference);
        if ($order->status->value !== 'delivered') {
            throw DomainException::conflict('review_not_allowed', 'Vous pourrez donner votre avis une fois la commande livrée.');
        }
        if (! $order->items()->where('product_id', $data['product_id'])->exists()) {
            throw DomainException::unprocessable('product_not_in_order', 'Ce produit ne fait pas partie de la commande.');
        }
        if (Review::query()->where('order_id', $order->id)->where('product_id', $data['product_id'])->exists()) {
            throw DomainException::conflict('review_exists', 'Vous avez déjà donné votre avis sur ce produit.');
        }
        $review = Review::query()->create([
            'order_id' => $order->id,
            'product_id' => $data['product_id'],
            'customer_id' => $order->customer_id,
            'author_name' => $order->customer_snapshot['first_name'] ?? 'Client',
            'stars' => $data['stars'],
            'comment' => $data['comment'] ?? null,
            'moderation_status' => 'pending',
        ]);

        return response()->json(['data' => ['id' => $review->id, 'moderation_status' => 'pending']], 201);
    }

    private function order(Request $request, string $reference): Order
    {
        $user = $request->user();
        if ($user?->customer && ! $request->hasHeader('X-Tracking-Token')) {
            $order = Order::query()->where('reference', strtoupper($reference))->where('customer_id', $user->customer->id)->first();
            if ($order) {
                return $order;
            }
        }

        return $this->tracking->resolve($reference, $request->header('X-Tracking-Token'));
    }
}
