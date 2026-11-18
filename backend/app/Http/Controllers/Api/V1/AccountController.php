<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Geo\GeoPoint;
use App\Domain\Geo\ZoneLocator;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Phone;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOrderResource;
use App\Models\Address;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Espace client (compte facultatif) : uniquement ses propres données. */
class AccountController extends Controller
{
    public function orders(Request $request): AnonymousResourceCollection
    {
        $orders = $this->customer($request)->orders()->where('status', '!=', 'draft')->with('items', 'invoices')->latest()->paginate(20);

        return PublicOrderResource::collection($orders);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:30'],
            'marketing_consent' => ['required', 'boolean'],
            'preferences' => ['nullable', 'array'],
            'preferences.favorite_flavors' => ['nullable', 'array', 'max:10'],
            'preferences.favorite_flavors.*' => ['string', 'max:40'],
        ]);
        $phone = Phone::normalizeSenegal($data['phone']) ?? throw DomainException::unprocessable('invalid_phone', 'Numéro de téléphone sénégalais invalide.');
        $customer = $this->customer($request);
        $customer->update([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'phone' => $phone,
            'preferences' => $data['preferences'] ?? $customer->preferences,
            // Consentement marketing distinct, révocable (LOY-011, SEC-013).
            'marketing_consent_at' => $data['marketing_consent'] ? ($customer->marketing_consent_at ?? now()) : null,
        ]);
        $request->user()->update(['name' => trim($data['first_name'].' '.($data['last_name'] ?? '')), 'phone' => $phone]);

        return response()->json(['data' => $customer->fresh()->only(['first_name', 'last_name', 'phone', 'preferences', 'marketing_consent_at'])]);
    }

    public function addresses(Request $request): JsonResponse
    {
        // Adresse par défaut en tête : c'est elle que le checkout présélectionne.
        return response()->json(['data' => $this->customer($request)->addresses()->with('zone:id,name')
            ->orderByDesc('is_default')->orderBy('created_at')->get()]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $this->validateAddress($request);
        $address = DB::transaction(function () use ($customer, $data) {
            // La première adresse devient l'adresse par défaut.
            $data['is_default'] = ($data['is_default'] ?? false) || ! $customer->addresses()->exists();
            $address = $customer->addresses()->create($data);

            return $this->keepSingleDefault($address);
        });

        return response()->json(['data' => $address->load('zone:id,name')], 201);
    }

    public function updateAddress(Request $request, string $id): JsonResponse
    {
        $address = $this->customer($request)->addresses()->findOrFail($id);
        $data = $this->validateAddress($request);
        // Une adresse par défaut ne peut pas être « dé-définie » sans en choisir une autre.
        if ($address->is_default) {
            unset($data['is_default']);
        }
        DB::transaction(fn () => $this->keepSingleDefault(tap($address)->update($data)));

        return response()->json(['data' => $address->load('zone:id,name')]);
    }

    /** Définit l'adresse de livraison par défaut (une seule par client). */
    public function defaultAddress(Request $request, string $id): JsonResponse
    {
        $address = $this->customer($request)->addresses()->findOrFail($id);
        DB::transaction(fn () => $this->keepSingleDefault(tap($address)->update(['is_default' => true])));

        return response()->json(['data' => $address->load('zone:id,name')]);
    }

    public function destroyAddress(Request $request, string $id): Response
    {
        $customer = $this->customer($request);
        $address = $customer->addresses()->findOrFail($id);
        DB::transaction(function () use ($customer, $address) {
            $address->delete();
            // L'adresse par défaut supprimée est remplacée par la plus ancienne restante.
            if ($address->is_default) {
                $customer->addresses()->orderBy('created_at')->first()?->update(['is_default' => true]);
            }
        });

        return response()->noContent();
    }

    private function keepSingleDefault(Address $address): Address
    {
        if ($address->is_default) {
            Address::query()->where('customer_id', $address->customer_id)->whereKeyNot($address->id)
                ->where('is_default', true)->update(['is_default' => false]);
        }

        return $address;
    }

    private function validateAddress(Request $request): array
    {
        $data = $request->validate([
            'zone_id' => ['required', 'uuid', 'exists:delivery_zones,id'],
            'label' => ['nullable', 'string', 'max:60'],
            'district' => ['required', 'string', 'max:120'],
            'street' => ['nullable', 'string', 'max:200'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'numeric', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'required_with:lat'],
            'location_source' => ['nullable', 'in:gps,map,search'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        // La position est enregistrée et la zone recalculée comme à la commande (docs/audit-geolocalisation).
        // PUT = remplacement complet : une modification sans lat/lng retire la position enregistrée.
        $point = GeoPoint::maybe($data['lat'] ?? null, $data['lng'] ?? null);
        $located = app(ZoneLocator::class)->locateOrFail($point, $data['zone_id']);
        $data['zone_id'] = $located['zone']?->id ?? $data['zone_id'];
        $data['lat'] = $point?->lat;
        $data['lng'] = $point?->lng;
        $data['location_source'] = $point ? ($data['location_source'] ?? null) : null;
        $data['location_accuracy_m'] = $point ? ($data['accuracy_m'] ?? null) : null;
        unset($data['accuracy_m']);

        return $data;
    }

    private function customer(Request $request): Customer
    {
        return $request->user()->customer ?? throw new DomainException('no_customer_profile', 'Aucun profil client associé à ce compte.', 404);
    }
}
