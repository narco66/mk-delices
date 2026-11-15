<?php

namespace App\Http\Controllers\Api\V1\Courier;

use App\Domain\Deliveries\AssignmentStatus;
use App\Domain\Deliveries\DeliveryService;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Http\Controllers\Controller;
use App\Http\Resources\MissionResource;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Espace livreur : uniquement ses propres missions (SEC-003, T-013). */
class MissionController extends Controller
{
    public function index(Request $request, Settings $settings): JsonResponse
    {
        $partner = $this->partner($request);
        $base = DeliveryAssignment::query()->where('partner_id', $partner->id)->with(['order.items.product:id,requires_cold_chain']);

        return response()->json(['data' => [
            'partner' => ['display_name' => $partner->display_name, 'available' => $partner->available],
            'active' => MissionResource::collection((clone $base)->whereIn('status', AssignmentStatus::active())->orderBy('assigned_at')->get())->resolve(),
            'history' => MissionResource::collection((clone $base)->whereNotIn('status', AssignmentStatus::active())->latest('assigned_at')->limit(50)->get())->resolve(),
            'proof_types' => $settings->get('delivery.proof_types'),
            'incident_reasons' => $settings->get('delivery.incident_reasons'),
            'amount_due_xof' => (int) (clone $base)->where('status', 'delivered')->whereNull('settled_at')->sum('partner_fee_xof'),
        ]]);
    }

    public function show(Request $request, string $id): MissionResource
    {
        return new MissionResource($this->mission($request, $id)->load('order.items.product:id,requires_cold_chain'));
    }

    public function action(Request $request, string $id, DeliveryService $deliveries): MissionResource
    {
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', DeliveryService::COURIER_ACTIONS)],
            'reason' => ['nullable', 'string', 'max:300'],
            'packaging_checked' => ['nullable', 'boolean'],
            'proof_type' => ['nullable', 'string', 'max:40'],
            'proof_reference' => ['nullable', 'string', 'max:255'],
            'cash_collected_xof' => ['nullable', 'integer', 'min:0'],
            'incident_reason' => ['nullable', 'string', 'max:60'],
            'incident_note' => ['nullable', 'string', 'max:1000'],
            'product_condition' => ['nullable', 'in:intact,abime,rupture_froid,inconnu'],
            'contact_attempted' => ['nullable', 'boolean'],
        ]);
        $mission = $deliveries->courierAction($this->mission($request, $id), $request->user(), $data['action'], $data);

        return new MissionResource($mission->load('order.items.product:id,requires_cold_chain'));
    }

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean']]);
        $partner = $this->partner($request);
        $partner->update(['available' => $data['available']]);

        return response()->json(['data' => ['available' => $partner->available]]);
    }

    private function partner(Request $request): DeliveryPartner
    {
        $partner = $request->user()->deliveryPartner;
        if (! $partner || ! $partner->active) {
            throw DomainException::forbidden('Profil livreur inactif ou introuvable.');
        }

        return $partner;
    }

    private function mission(Request $request, string $id): DeliveryAssignment
    {
        $partner = $this->partner($request);
        $mission = DeliveryAssignment::query()->find($id);
        // Même réponse 403 que la mission existe ou non chez un autre livreur : pas d'énumération.
        if (! $mission || $mission->partner_id !== $partner->id) {
            throw DomainException::forbidden('Cette mission ne vous est pas affectée.');
        }

        return $mission;
    }
}
