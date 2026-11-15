<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\RefundService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\RefundResource;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Rapprochement des paiements et remboursements sous double contrôle. */
class FinanceController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function payments(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'string'], 'provider' => ['nullable', 'string', 'max:40'], 'q' => ['nullable', 'string', 'max:40']]);
        $payments = Payment::query()->with('order:id,reference')
            ->when($request->input('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('provider_reference', 'ilike', "%{$t}%")
                ->orWhereHas('order', fn ($o) => $o->where('reference', 'ilike', "%{$t}%"))))
            ->latest()->paginate(50);

        return PaymentResource::collection($payments);
    }

    public function paymentEvents(): JsonResponse
    {
        return response()->json(['data' => PaymentEvent::query()->latest('received_at')->limit(100)
            ->get(['id', 'payment_id', 'provider', 'provider_event_id', 'event_type', 'processing_status', 'processing_note', 'received_at', 'processed_at'])]);
    }

    public function refundIndex(Request $request): AnonymousResourceCollection
    {
        $refunds = Refund::query()->with(['order:id,reference', 'requester:id,name', 'approver:id,name'])
            ->when($request->input('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->latest()->paginate(50);

        return RefundResource::collection($refunds);
    }

    public function approve(Request $request, Refund $refund): RefundResource
    {
        return new RefundResource($this->refunds->approve($refund, $request->user())->load('order:id,reference'));
    }

    public function reject(Request $request, Refund $refund): RefundResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new RefundResource($this->refunds->reject($refund, $request->user(), $data['reason']));
    }

    public function complete(Request $request, Refund $refund): RefundResource
    {
        $data = $request->validate(['provider_ref' => ['required', 'string', 'min:3', 'max:120']]);

        return new RefundResource($this->refunds->complete($refund, $data['provider_ref'], $request->user()));
    }
}
