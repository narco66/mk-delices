<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Deliveries\AssignmentStatus;
use App\Domain\Deliveries\DeliveryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\OrderResource;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryPartner;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    /** Tableau d'affectation : commandes prêtes / en échec, missions actives, livreurs éligibles. */
    public function board(): JsonResponse
    {
        $orders = Order::query()->whereIn('status', ['ready', 'delivery_failed', 'assigned', 'picked_up'])
            ->where('fulfillment_type', 'delivery')
            ->with(['zone', 'activeAssignment.partner', 'assignments' => fn ($q) => $q->with('partner')->latest('assigned_at')])
            ->orderByRaw('slot_start_at ASC NULLS LAST')->get();

        $partners = DeliveryPartner::query()->with('zones:id,name')
            ->withCount(['assignments as active_missions' => fn ($q) => $q->whereIn('status', AssignmentStatus::active())])
            ->orderBy('display_name')->get();

        return response()->json(['data' => [
            'orders' => $orders->map(fn (Order $o) => (new OrderResource($o))->resolve() + [
                'active_assignment' => $o->activeAssignment ? (new AssignmentResource($o->activeAssignment))->resolve() : null,
                'last_incident' => optional($o->assignments->firstWhere('status', AssignmentStatus::Failed), fn ($a) => (new AssignmentResource($a))->resolve()),
            ]),
            'partners' => $partners->map(fn (DeliveryPartner $p) => [
                'id' => $p->id,
                'display_name' => $p->display_name,
                'transport_mode' => $p->transport_mode,
                'active' => $p->active,
                'available' => $p->available,
                'active_missions' => $p->active_missions,
                'zone_ids' => $p->zones->pluck('id'),
                'zones' => $p->zones->pluck('name'),
            ]),
        ]]);
    }

    public function assign(Request $request): AssignmentResource
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'partner_id' => ['required', 'uuid'],
        ]);
        $assignment = $this->deliveries->assign(Order::query()->findOrFail($data['order_id']), DeliveryPartner::query()->findOrFail($data['partner_id']), $request->user());

        return new AssignmentResource($assignment->load('partner', 'order'));
    }

    public function cancel(Request $request, DeliveryAssignment $assignment): AssignmentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new AssignmentResource($this->deliveries->cancel($assignment, $request->user(), $data['reason'])->load('partner', 'order'));
    }

    public function missions(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string'], 'partner_id' => ['nullable', 'uuid']]);
        $missions = DeliveryAssignment::query()->with(['partner', 'order'])
            ->when($request->input('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('partner_id'), fn ($q, $p) => $q->where('partner_id', $p))
            ->latest('assigned_at')->paginate(50);

        return AssignmentResource::collection($missions)->response();
    }

    /** Montants dus par livreur (missions livrées non réglées). */
    public function payouts(): JsonResponse
    {
        $rows = DB::select(
            "SELECT p.id, p.display_name,
                    count(a.id) FILTER (WHERE a.status IN ('delivered','failed') AND (a.partner_fee_xof > 0 OR a.cash_collected_xof > 0) AND a.settled_at IS NULL)::int AS unsettled_missions,
                    coalesce(sum(a.partner_fee_xof) FILTER (WHERE a.status IN ('delivered','failed') AND a.settled_at IS NULL), 0)::bigint AS amount_due_xof,
                    coalesce(sum(a.cash_collected_xof) FILTER (WHERE a.status = 'delivered' AND a.settled_at IS NULL), 0)::bigint AS cash_held_xof,
                    count(a.id) FILTER (WHERE a.status = 'delivered')::int AS delivered_total,
                    count(a.id) FILTER (WHERE a.status = 'failed')::int AS failed_total
             FROM delivery_partners p LEFT JOIN delivery_assignments a ON a.partner_id = p.id
             GROUP BY p.id, p.display_name ORDER BY p.display_name"
        );
        $unsettled = DeliveryService::payable(DeliveryAssignment::query()->with(['partner', 'order']))->whereNull('settled_at')->latest('closed_at')->get();

        return response()->json(['data' => [
            'partners' => array_map(fn ($r) => (array) $r + ['amount_due_xof' => (int) $r->amount_due_xof, 'cash_held_xof' => (int) $r->cash_held_xof], $rows),
            'unsettled' => AssignmentResource::collection($unsettled)->resolve(),
        ]]);
    }

    public function settle(Request $request): JsonResponse
    {
        $data = $request->validate(['assignment_ids' => ['required', 'array', 'min:1', 'max:500'], 'assignment_ids.*' => ['uuid']]);

        return response()->json(['data' => ['settled' => $this->deliveries->settle($data['assignment_ids'], $request->user())]]);
    }
}
