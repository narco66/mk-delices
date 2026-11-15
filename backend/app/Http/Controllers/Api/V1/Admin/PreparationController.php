<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Shared\Settings;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * File de préparation (UX-09) : informations utiles à l'atelier uniquement
 * (produits, quantités, allergènes, conservation, créneau, consignes) — pas de coordonnées client.
 */
class PreparationController extends Controller
{
    public function index(Request $request, OrderStateMachine $machine, Settings $settings): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $orders = Order::query()
            ->whereIn('status', ['accepted', 'preparing', 'ready'])
            ->when($request->input('date'), fn ($q, $d) => $q->whereRaw("(slot_start_at AT TIME ZONE 'Africa/Dakar')::date = ?", [$d]))
            ->with(['items.product:id,requires_cold_chain,shelf_life_hours'])
            ->orderByRaw('slot_start_at ASC NULLS LAST')
            ->limit(200)
            ->get();

        $now = now();
        $undoMinutes = (int) $settings->get('preparation.undo_window_minutes', 15);
        $data = $orders->map(fn (Order $o) => [
            'id' => $o->id,
            'reference' => $o->reference,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'version' => $o->version,
            'slot_start_at' => $o->slot_start_at?->toIso8601ZuluString(),
            'slot_end_at' => $o->slot_end_at?->toIso8601ZuluString(),
            'minutes_until_slot' => $o->slot_start_at ? (int) $now->diffInMinutes($o->slot_start_at, false) : null,
            'priority' => $o->slot_start_at && $now->diffInMinutes($o->slot_start_at, false) < 120 ? 'urgent' : 'normal',
            'first_name' => $o->customer_snapshot['first_name'] ?? null,
            'customer_notes' => $o->customer_notes,
            'fulfillment_type' => $o->fulfillment_type,
            'accepted_at' => $o->accepted_at?->toIso8601ZuluString(),
            'preparing_at' => $o->preparing_at?->toIso8601ZuluString(),
            // Annulation d'un démarrage par erreur : possible jusqu'à cette heure (null = plus possible).
            'undo_until' => $machine->withinUndoWindow($o) ? $o->preparing_at->copy()->addMinutes($undoMinutes)->toIso8601ZuluString() : null,
            'ready_at' => $o->ready_at?->toIso8601ZuluString(),
            'requires_cold_chain' => $o->items->contains(fn ($i) => (bool) $i->product?->requires_cold_chain),
            'items' => $o->items->map(fn ($i) => [
                'product_name' => $i->product_name_snapshot,
                'variant_label' => $i->variant_label_snapshot,
                'sku' => $i->sku_snapshot,
                'qty' => $i->qty,
                'allergens' => $i->allergens_snapshot,
                'storage' => $i->storage_snapshot,
                'prep_minutes' => $i->prep_minutes_snapshot,
                'shelf_life_hours' => $i->product?->shelf_life_hours,
            ]),
        ]);

        return response()->json(['data' => [
            'accepted' => $data->where('status', 'accepted')->values(),
            'preparing' => $data->where('status', 'preparing')->values(),
            'ready' => $data->where('status', 'ready')->values(),
        ]]);
    }
}
