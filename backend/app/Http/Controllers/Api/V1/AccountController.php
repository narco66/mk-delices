<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customers\LoyaltyService;
use App\Domain\Geo\GeoPoint;
use App\Domain\Geo\ZoneLocator;
use App\Domain\Orders\OrderStatus;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Phone;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PublicOrderResource;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Espace client (compte facultatif) : uniquement ses propres données. */
class AccountController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

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
        AuthController::assertPhoneFree($phone, $request->user()->id);
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

    // ---- Favoris ---------------------------------------------------------------------------------

    /** Produits favoris encore visibles en boutique (un produit retiré disparaît de la liste sans erreur). */
    public function favorites(Request $request): AnonymousResourceCollection
    {
        $products = $request->user()->favoriteProducts()->publiclyVisible()
            ->with(['category', 'variants', 'images'])->orderByPivot('created_at', 'desc')->get();

        return ProductResource::collection($products);
    }

    public function addFavorite(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'uuid']]);
        $product = Product::query()->publiclyVisible()->find($data['product_id'])
            ?? throw new DomainException('product_not_found', 'Ce produit n\'est plus proposé.', 404);
        if ($request->user()->favoriteProducts()->count() >= self::MAX_FAVORITES) {
            throw DomainException::unprocessable('too_many_favorites', 'Vous avez atteint le nombre maximal de favoris ('.self::MAX_FAVORITES.').');
        }
        $request->user()->favoriteProducts()->syncWithoutDetaching([$product->id]);

        return response()->json(['data' => ['product_id' => $product->id, 'favorite' => true]], 201);
    }

    public function removeFavorite(Request $request, string $id): Response
    {
        $request->user()->favoriteProducts()->detach($id);

        return response()->noContent();
    }

    // ---- Club MK ---------------------------------------------------------------------------------

    public function loyalty(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        return response()->json(['data' => $loyalty->card($this->customer($request))]);
    }

    // ---- Suppression du compte -------------------------------------------------------------------

    /**
     * Suppression du compte par le client (exigence Google Play et App Store, RGPD/CDP). Le compte est anonymisé :
     * identité, coordonnées, adresses, favoris, appareils et connexions sont effacés. Les commandes et factures
     * sont conservées sans lien avec le compte (obligations comptables), comme les avis (signés « Client »).
     */
    public function destroy(Request $request): Response
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);
        /** @var User $user */
        $user = $request->user();
        if ($user->isStaff() || $user->hasRole(User::ROLE_LIVREUR)) {
            throw new DomainException('staff_account', 'Les comptes du personnel et des livreurs sont supprimés par un administrateur.', 403);
        }
        if (! Hash::check($data['password'], (string) $user->password)) {
            throw DomainException::unprocessable('invalid_password', 'Mot de passe incorrect.');
        }
        $customer = $user->customer;
        $open = [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::Rejected->value, OrderStatus::Draft->value];
        if ($customer && $customer->orders()->notTest()->whereNotIn('status', $open)->exists()) {
            throw DomainException::conflict('active_orders', 'Une commande est en cours : vous pourrez supprimer votre compte une fois qu\'elle sera livrée ou annulée.');
        }

        DB::transaction(function () use ($user, $customer) {
            $this->audit->log('account.deleted', $user, null, ['customer_id' => $customer?->id], $user->id);
            $user->mobileDevices()->delete();
            $user->pushSubscriptions()->delete();
            $user->favoriteProducts()->detach();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            if ($customer) {
                $customer->addresses()->delete();
                Review::query()->where('customer_id', $customer->id)->update(['author_name' => 'Client MK Délices']);
                $customer->forceFill([
                    'user_id' => null, 'first_name' => 'Client', 'last_name' => null, 'phone' => '', 'email' => null,
                    'preferences' => null, 'marketing_consent_at' => null,
                ])->save();
            }
            $user->syncRoles([]);
            // is_active passe à false : User::booted révoque tous les jetons mobiles.
            $user->forceFill([
                'name' => 'Compte supprimé', 'email' => null, 'phone' => null, 'password' => null,
                'remember_token' => null, 'is_active' => false, 'anonymized_at' => now(),
            ])->save();
        });
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private const MAX_FAVORITES = 100;

    private function customer(Request $request): Customer
    {
        return $request->user()->customer ?? throw new DomainException('no_customer_profile', 'Aucun profil client associé à ce compte.', 404);
    }
}
