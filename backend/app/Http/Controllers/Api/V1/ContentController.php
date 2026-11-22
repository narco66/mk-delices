<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customers\Push\MobilePushNotifier;
use App\Domain\Geo\Geocoder;
use App\Domain\Geo\ZoneLocator;
use App\Domain\Payments\GatewayRegistry;
use App\Domain\Shared\Settings;
use App\Http\Controllers\Controller;
use App\Models\ContentBlock;
use App\Models\ContentPage;
use App\Models\DeliveryZone;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;

class ContentController extends Controller
{
    /** Configuration publique : identité, horaires, moyens de paiement réellement disponibles. */
    public function config(Settings $settings, GatewayRegistry $gateways): JsonResponse
    {
        return response()->json(['data' => [
            'settings' => $settings->public(),
            'payment_methods' => $gateways->availableMethods(),
            // Paiement réel suspendu : message à afficher à la place de Wave, Orange Money et carte.
            'online_payment' => [
                'enabled' => $gateways->onlinePaymentEnabled(),
                'notice' => $gateways->onlinePaymentEnabled() ? null : 'Le paiement en ligne (Wave, Orange Money, carte) sera bientôt disponible.',
            ],
            // Notifications push de l'application mobile : l'application ne demande l'autorisation que si elles sont actives.
            'push' => ['mobile' => app(MobilePushNotifier::class)->enabled()],
            'currency' => 'XOF',
            'timezone' => $settings->timezone(),
            // Carte : affichée seulement si un fond de carte commercial est configuré (DEC-GEO-01).
            'maps' => [
                'tiles_url' => config('mkdelices.maps.tiles_url') ?: null,
                'attribution' => config('mkdelices.maps.tiles_attribution'),
                'geocoding' => app(Geocoder::class)->enabled(),
                'default_center' => config('mkdelices.maps.default_center'),
                'bounds' => config('mkdelices.maps.bounds'),
                // Périmètre desservi (contour public : limite de la ville), affiché sur la carte du client.
                'service_area' => app(ZoneLocator::class)->serviceArea(),
            ],
        ]]);
    }

    public function home(): JsonResponse
    {
        $blocks = ContentBlock::query()->where('status', 'published')->orderBy('position')->get(['key', 'group', 'data']);

        return response()->json(['data' => $blocks->mapWithKeys(fn ($b) => [$b->key => $b->data])]);
    }

    public function page(string $slug): JsonResponse
    {
        $page = ContentPage::query()->where('slug', $slug)->where('status', 'published')
            ->firstOrFail(['slug', 'title', 'excerpt', 'body', 'seo_title', 'seo_description', 'requires_validation', 'published_at', 'updated_at']);

        return response()->json(['data' => $page]);
    }

    public function zones(): JsonResponse
    {
        $zones = DeliveryZone::query()->where('active', true)->orderBy('position')->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'districts', 'delivery_fee_xof', 'min_order_xof', 'area']);

        return response()->json(['data' => $zones]);
    }

    public function promotions(): JsonResponse
    {
        $promotions = Promotion::query()->where('active', true)->where('is_public', true)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->get(['code', 'name', 'description', 'discount_type', 'discount_value', 'min_subtotal_xof', 'ends_at']);

        return response()->json(['data' => $promotions]);
    }
}
