<?php

namespace App\Http\Controllers\Api\V1\Courier;

use App\Domain\Deliveries\AssignmentStatus;
use App\Domain\Deliveries\DeliveryService;
use App\Domain\Deliveries\Push\CourierPushNotifier;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Settings;
use App\Http\Controllers\Controller;
use App\Http\Resources\MissionResource;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryPartner;
use App\Models\PushSubscription;
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
            // Règle de rémunération des échecs, affichée au livreur avant validation.
            'client_fault_reasons' => $settings->get('delivery.client_fault_reasons'),
            'client_fault_min_wait_minutes' => (int) $settings->get('delivery.client_fault_min_wait_minutes', 10),
            'failed_fee_percent' => (int) $settings->get('delivery.failed_fee_percent', 0),
            'delivery_code_enabled' => (bool) $settings->get('delivery.code_enabled', false),
            // Clé publique VAPID (null = notifications du navigateur non configurées).
            'push_public_key' => CourierPushNotifier::enabled() ? config('mkdelices.push.vapid_public_key') : null,
            'amount_due_xof' => (int) DeliveryService::payable(clone $base)->whereNull('settled_at')->sum('partner_fee_xof'),
            // Audit espace livreur M2 : espèces encaissées pas encore remises (réglées) à MK Délices.
            'cash_to_hand_over_xof' => (int) (clone $base)->where('status', AssignmentStatus::Delivered->value)->whereNull('settled_at')->sum('cash_collected_xof'),
        ]]);
    }

    public function show(Request $request, string $id): MissionResource
    {
        return new MissionResource($this->mission($request, $id)->load('order.items.product:id,requires_cold_chain'));
    }

    public function action(Request $request, string $id, DeliveryService $deliveries, Settings $settings): MissionResource
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
        $mission = $this->mission($request, $id);
        if ($data['action'] === 'deliver' && ($data['proof_type'] ?? null) === 'code' && $settings->get('delivery.code_enabled', false)) {
            $deliveries->verifyDeliveryCode($mission, $request->user(), (string) ($data['proof_reference'] ?? ''));
        }
        $mission = $deliveries->courierAction($mission, $request->user(), $data['action'], $data);

        return new MissionResource($mission->load('order.items.product:id,requires_cold_chain'));
    }

    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean']]);
        $partner = $this->partner($request);
        $partner->update(['available' => $data['available']]);

        return response()->json(['data' => ['available' => $partner->available]]);
    }

    /** Abonnement de cet appareil aux notifications du navigateur (nouvelle mission). */
    public function subscribePush(Request $request): JsonResponse
    {
        if (! CourierPushNotifier::enabled()) {
            throw DomainException::conflict('push_disabled', 'Les notifications ne sont pas configurées sur ce serveur.');
        }
        $this->partner($request);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', function (string $attr, mixed $value, \Closure $fail) {
                if (! CourierPushNotifier::isAllowedEndpoint((string) $value)) {
                    $fail('Service de notification non reconnu.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+=*$/'],
            'keys.auth' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+=*$/'],
        ]);
        $user = $request->user();
        // Un appareil partagé appartient au dernier livreur qui s'y est abonné.
        PushSubscription::query()->updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $user->id,
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        // Au plus 5 appareils par livreur : les plus anciens abonnements sont retirés.
        $user->pushSubscriptions()->latest('updated_at')->skip(5)->take(100)->get()->each->delete();

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    public function unsubscribePush(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $request->user()->pushSubscriptions()->where('endpoint', $data['endpoint'])->delete();

        return response()->json(['data' => ['subscribed' => false]]);
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
