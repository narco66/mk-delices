<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Téléphones enregistrés pour les notifications push (clients et livreurs, application mobile).
 * Le jeton FCM est lié au jeton de connexion de l'appareil : déconnexion, mot de passe changé ou compte
 * désactivé suppriment l'enregistrement (plus aucune notification vers un téléphone déconnecté).
 */
class DeviceController extends Controller
{
    private const MAX_DEVICES = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:4096'],
            'platform' => ['required', Rule::in(MobileDevice::PLATFORMS)],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);
        $user = $request->user();
        $accessToken = $user->currentAccessToken();
        // Audit S-18 : réservé à l'application (jeton Bearer). Un enregistrement fait depuis le site (session)
        // ne serait lié à aucun jeton, donc jamais purgé à la déconnexion.
        if (! $accessToken instanceof PersonalAccessToken) {
            throw new DomainException('mobile_app_only', "Les notifications sur téléphone s'activent depuis l'application MK Délices.", 403);
        }
        $hash = MobileDevice::hashToken($data['token']);
        $previousOwner = MobileDevice::query()->where('token_hash', $hash)->value('user_id');
        // Le même téléphone peut changer de compte : l'enregistrement est réattribué au compte connecté (tracé).
        $device = MobileDevice::query()->updateOrCreate(['token_hash' => $hash], [
            'user_id' => $user->id,
            'token' => $data['token'],
            'platform' => $data['platform'],
            'app_version' => $data['app_version'] ?? null,
            'access_token_id' => $accessToken->id,
        ]);
        if ($previousOwner !== null && $previousOwner !== $user->id) {
            $this->audit->log('device.reassigned', $device, ['user_id' => $previousOwner], ['user_id' => $user->id]);
        }
        $keep = $user->mobileDevices()->latest('updated_at')->limit(self::MAX_DEVICES)->pluck('id');
        $user->mobileDevices()->whereNotIn('id', $keep)->delete();

        return response()->json(['data' => $device->only(['id', 'platform', 'app_version', 'created_at', 'updated_at'])], $device->wasRecentlyCreated ? 201 : 200);
    }

    /** Désinscription (notifications désactivées dans l'application, ou avant déconnexion). */
    public function destroy(Request $request, string $id): Response
    {
        $request->user()->mobileDevices()->whereKey($id)->delete();

        return response()->noContent();
    }
}
