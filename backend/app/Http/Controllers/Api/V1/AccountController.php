<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\DomainException;
use App\Domain\Shared\Phone;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOrderResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
        return response()->json(['data' => $this->customer($request)->addresses()->with('zone:id,name')->get()]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $address = $this->customer($request)->addresses()->create($this->validateAddress($request));

        return response()->json(['data' => $address->load('zone:id,name')], 201);
    }

    public function updateAddress(Request $request, string $id): JsonResponse
    {
        $address = $this->customer($request)->addresses()->findOrFail($id);
        $address->update($this->validateAddress($request));

        return response()->json(['data' => $address->load('zone:id,name')]);
    }

    public function destroyAddress(Request $request, string $id): Response
    {
        $this->customer($request)->addresses()->findOrFail($id)->delete();

        return response()->noContent();
    }

    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'zone_id' => ['required', 'uuid', 'exists:delivery_zones,id'],
            'label' => ['nullable', 'string', 'max:60'],
            'district' => ['required', 'string', 'max:120'],
            'street' => ['nullable', 'string', 'max:200'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }

    private function customer(Request $request): Customer
    {
        return $request->user()->customer ?? throw new DomainException('no_customer_profile', 'Aucun profil client associé à ce compte.', 404);
    }
}
