<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Cart\CartService;
use App\Domain\Orders\CheckoutService;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\RefundService;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Idempotency;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private readonly OrderStateMachine $machine) {}

    /**
     * Commande saisie par l'équipe (téléphone, boutique) à partir d'un panier de travail (en-têtes X-Cart-Id / X-Cart-Token) :
     * prix, créneau, stock et promotions sont recalculés exactement comme pour une commande en ligne.
     */
    public function store(Request $request, CartService $carts, CheckoutService $checkout, Idempotency $idempotency): JsonResponse
    {
        $delivery = $request->input('fulfillment_type', 'delivery') === 'delivery';
        $data = $request->validate([
            'channel' => ['required', 'in:phone,backoffice'],
            'customer.first_name' => ['required', 'string', 'min:2', 'max:80'],
            'customer.last_name' => ['nullable', 'string', 'max:80'],
            'customer.phone' => ['required', 'string', 'max:30', 'regex:/^[+0-9 ().-]{9,20}$/'],
            'customer.email' => ['nullable', 'email:rfc', 'max:160'],
            'fulfillment_type' => ['nullable', 'in:delivery,pickup'],
            'delivery' => [$delivery ? 'required' : 'nullable', 'array'],
            'delivery.zone_id' => [$delivery ? 'required' : 'nullable', 'uuid'],
            'delivery.district' => [$delivery ? 'required' : 'nullable', 'string', 'min:2', 'max:120'],
            'delivery.street' => ['nullable', 'string', 'max:200'],
            'delivery.landmark' => ['nullable', 'string', 'max:255'],
            'slot_start_at' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'promotion_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'customer.first_name' => 'prénom', 'customer.phone' => 'téléphone', 'customer.email' => 'email',
            'delivery.zone_id' => 'zone de livraison', 'delivery.district' => 'quartier', 'slot_start_at' => 'créneau',
        ]);
        $cart = $carts->resolve((string) $request->header('X-Cart-Id'), $request->header('X-Cart-Token'));

        $result = $idempotency->run('admin.orders', $request->header('Idempotency-Key'), $data + ['cart_id' => $cart->id], function () use ($cart, $data, $checkout, $request) {
            $placed = $checkout->placeOrder($cart, $data, null, $request->user());

            return ['status' => 201, 'body' => ['data' => [
                'id' => $placed['order']->id,
                'reference' => $placed['order']->reference,
                'total_xof' => $placed['order']->total_xof,
                'tracking_token' => $placed['tracking_token'],
            ]]];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:200'],
            'payment_status' => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable', 'in:web,whatsapp,phone,backoffice'],
            'q' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'slot_date' => ['nullable', 'date'],
            'include_test' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:created_desc,created_asc,slot_asc,total_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Order::query()->with('zone')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($filters['payment_status'] ?? null, fn ($q, $s) => $q->whereIn('payment_status', explode(',', $s)))
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($filters['slot_date'] ?? null, fn ($q, $d) => $q->whereRaw("(slot_start_at AT TIME ZONE 'Africa/Dakar')::date = ?", [$d]))
            ->when(! $request->boolean('include_test'), fn ($q) => $q->where('is_test', false))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('reference', 'ilike', $like)
                    ->orWhereRaw("customer_snapshot->>'first_name' ILIKE ?", [$like])
                    ->orWhereRaw("customer_snapshot->>'last_name' ILIKE ?", [$like])
                    ->orWhereRaw("customer_snapshot->>'phone' LIKE ?", ['%'.preg_replace('/\D/', '', $term).'%']));
            });
        match ($filters['sort'] ?? 'created_desc') {
            'created_asc' => $query->orderBy('created_at'),
            'slot_asc' => $query->orderByRaw('slot_start_at ASC NULLS LAST'),
            'total_desc' => $query->orderByDesc('total_xof'),
            default => $query->orderByDesc('created_at'),
        };

        return OrderResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    public function show(Request $request, Order $order): OrderResource
    {
        // Atelier sans accès commercial (audit lot 3) : seulement les commandes en cours de production,
        // avec les informations utiles à la préparation (ni historique, ni journal, ni missions).
        if (! $request->user()->can('orders.view')) {
            abort_unless(in_array($order->status->value, ['accepted', 'preparing', 'ready'], true), 404);
            $order->load(['items', 'zone']);

            return OrderResource::make($order)->additional(['meta' => ['available_actions' => $this->machine->availableActions($order, $request->user())]]);
        }
        $order->load(['items', 'zone', 'events.actor', 'payments.events', 'refunds.requester', 'refunds.approver', 'assignments.partner', 'invoices']);

        return OrderResource::make($order)->additional(['meta' => ['available_actions' => $this->machine->availableActions($order, $request->user())]]);
    }

    public function transition(Request $request, Order $order, CheckoutService $checkout): OrderResource
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(OrderStateMachine::ACTIONS))],
            'reason' => ['nullable', 'string', 'max:500'],
            'expected_version' => ['nullable', 'integer'],
            'packaging_checked' => ['nullable', 'boolean'],
        ]);
        $updated = $data['action'] === 'submit'
            ? $checkout->confirmDraft($order, $request->user(), $data['expected_version'] ?? null)
            : $this->machine->apply($order, $data['action'], $request->user(), $data['reason'] ?? null, $data['expected_version'] ?? null, ['packaging_checked' => $request->boolean('packaging_checked')]);

        return $this->show($request, $updated->fresh());
    }

    public function updateDetails(Request $request, Order $order, CheckoutService $checkout): OrderResource
    {
        $data = $request->validate([
            'customer' => ['nullable', 'array'],
            'customer.first_name' => ['nullable', 'string', 'min:2', 'max:80'],
            'customer.last_name' => ['nullable', 'string', 'max:80'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:160'],
            'delivery' => ['nullable', 'array'],
            'delivery.zone_id' => ['required_with:delivery', 'uuid'],
            'delivery.district' => ['required_with:delivery', 'string', 'max:120'],
            'delivery.street' => ['nullable', 'string', 'max:200'],
            'delivery.landmark' => ['nullable', 'string', 'max:255'],
            'slot_start_at' => ['nullable', 'date'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:300'],
            'expected_version' => ['nullable', 'integer'],
        ]);
        $updated = $checkout->updateDetails($order, $data, $request->user());

        return $this->show($request, $updated);
    }

    public function manualPayment(Request $request, Order $order, PaymentService $payments): PaymentResource
    {
        $data = $request->validate([
            'amount_xof' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'in:cash,wave_direct,orange_money_direct,bank_transfer,other'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        return new PaymentResource($payments->recordManualPayment($order, (int) $data['amount_xof'], $data['method'], $data['reason'], $request->user()));
    }

    public function requestRefund(Request $request, Order $order, RefundService $refunds): RefundResource
    {
        if (! $request->user()->can('refunds.request')) {
            throw DomainException::forbidden();
        }
        $data = $request->validate([
            'amount_xof' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $refund = DB::transaction(function () use ($order, $data, $refunds, $request) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            return $refunds->requestForOrder($locked, (int) $data['amount_xof'], $data['reason'], $request->user());
        });

        return new RefundResource($refund);
    }

    public function reconcilePayment(Payment $payment, PaymentService $payments): PaymentResource
    {
        return new PaymentResource($payments->reconcile($payment)->load('events'));
    }
}
