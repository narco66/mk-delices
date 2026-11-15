<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Domain\Shared\Phone;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;

/**
 * Authentification Sanctum à deux modes :
 * - site web (SPA) : session par cookies HttpOnly + CSRF, aucun jeton en localStorage (TRD §5) ;
 * - application mobile : jeton d'accès par appareil (« Authorization: Bearer »), expirant et révocable.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:160'],
            'password' => ['required', 'string', 'max:200'],
        ]);
        if (! Auth::guard('web')->attempt(['email' => mb_strtolower($credentials['email']), 'password' => $credentials['password']], $request->boolean('remember'))) {
            $this->audit->log('auth.login_failed', null, null, ['email_hash' => hash('sha256', mb_strtolower($credentials['email']))]);

            throw new DomainException('invalid_credentials', 'Identifiants incorrects.', 401);
        }
        /** @var User $user */
        $user = Auth::guard('web')->user();
        if (! $user->is_active) {
            Auth::guard('web')->logout();

            throw new DomainException('account_disabled', 'Ce compte est désactivé.', 403);
        }
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', $user, null, null, $user->id);

        return response()->json(['data' => $this->present($user)]);
    }

    /**
     * Application mobile : échange email + mot de passe contre un jeton d'accès propre à l'appareil
     * (en-tête « Authorization: Bearer <jeton> »). Mêmes règles que la connexion web : limitation des
     * tentatives, compte actif obligatoire, journal d'audit. Le jeton n'est renvoyé qu'une seule fois.
     */
    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:160'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $user = User::query()->where('email', mb_strtolower($data['email']))->first();
        // Hachage calculé même si l'email est inconnu : temps de réponse identique (pas d'énumération).
        $valid = Hash::check($data['password'], $user?->password ?? self::DUMMY_HASH) && $user !== null;
        if (! $valid) {
            $this->audit->log('auth.login_failed', null, null, ['email_hash' => hash('sha256', mb_strtolower($data['email'])), 'channel' => 'mobile']);

            throw new DomainException('invalid_credentials', 'Identifiants incorrects.', 401);
        }
        if (! $user->is_active) {
            throw new DomainException('account_disabled', 'Ce compte est désactivé.', 403);
        }
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['data' => $this->tokenPayload($user, $data['device_name'])], 201);
    }

    /** Appareils connectés (jetons actifs) de l'utilisateur ; le jeton courant est signalé. */
    public function tokens(Request $request): JsonResponse
    {
        $current = $this->currentToken($request)?->id;

        return response()->json(['data' => $request->user()->tokens()->latest('last_used_at')->get()->map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'device_name' => $t->name,
            'last_used_at' => $t->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $t->expires_at?->toIso8601ZuluString(),
            'created_at' => $t->created_at?->toIso8601ZuluString(),
            'current' => $t->id === $current,
        ])]);
    }

    /** Déconnexion d'un appareil précis (ex. téléphone perdu). */
    public function revokeToken(Request $request, string $id): Response
    {
        $token = $request->user()->tokens()->whereKey($id)->firstOrFail();
        $token->delete();
        $this->audit->log('auth.token_revoked', $request->user(), null, ['device_name' => $token->name]);

        return response()->noContent();
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Renseigné par l'application mobile : un jeton est renvoyé au lieu d'ouvrir une session web.
            'device_name' => ['nullable', 'string', 'min:2', 'max:100'],
            'first_name' => ['required', 'string', 'min:2', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:160', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'marketing_consent' => ['nullable', 'boolean'],
            'accepted_privacy' => ['accepted'],
        ]);
        $phone = Phone::normalizeSenegal($data['phone']);
        if ($phone === null) {
            throw DomainException::unprocessable('invalid_phone', 'Numéro de téléphone sénégalais invalide.');
        }
        // Inscription publique : uniquement le rôle client, jamais de droits back-office (TRD §5).
        $user = DB::transaction(function () use ($data, $phone) {
            $user = User::query()->create([
                'name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                'email' => mb_strtolower($data['email']),
                'phone' => $phone,
                'password' => $data['password'],
            ]);
            $user->assignRole(User::ROLE_CLIENT);
            Customer::query()->create([
                'user_id' => $user->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'phone' => $phone,
                'email' => $user->email,
                'marketing_consent_at' => ! empty($data['marketing_consent']) ? now() : null,
            ]);

            return $user;
        });
        if (! empty($data['device_name'])) {
            return response()->json(['data' => $this->tokenPayload($user, $data['device_name'])], 201);
        }
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function logout(Request $request): Response
    {
        // Application mobile : seul le jeton de cet appareil est révoqué.
        if ($token = $this->currentToken($request)) {
            $token->delete();
            $this->audit->log('auth.token_revoked', $request->user(), null, ['device_name' => $token->name]);

            return response()->noContent();
        }
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    /** Nombre maximal d'appareils connectés par compte : au-delà, les plus anciens jetons sont révoqués. */
    private const MAX_DEVICES = 10;

    /** Empreinte bcrypt d'une valeur aléatoire, utilisée quand l'email est inconnu (temps constant). */
    private const DUMMY_HASH = '$2y$12$gBQyo6dQMRO5vThpx.nqreQAjUvtECJV.uu./XVBVR2ryoXo4NOga';

    private function tokenPayload(User $user, string $deviceName): array
    {
        $minutes = (int) config('sanctum.expiration');
        $expiresAt = $minutes > 0 ? now()->addMinutes($minutes) : null;
        $issued = $user->createToken(mb_substr($deviceName, 0, 100), ['*'], $expiresAt);
        $keep = $user->tokens()->latest('id')->limit(self::MAX_DEVICES)->pluck('id');
        $user->tokens()->whereNotIn('id', $keep)->delete();
        $this->audit->log('auth.token_issued', $user, null, ['device_name' => $issued->accessToken->name], $user->id);

        return [
            'token' => $issued->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601ZuluString(),
            'user' => $this->present($user),
        ];
    }

    private function currentToken(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    private function present(User $user): array
    {
        $user->loadMissing('customer', 'deliveryPartner');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->hasRole(User::ROLE_ADMIN)
                ? Permission::query()->pluck('name')->values()
                : $user->getAllPermissions()->pluck('name')->values(),
            'is_staff' => $user->isStaff(),
            'is_courier' => $user->hasRole(User::ROLE_LIVREUR),
            'customer' => $user->customer ? [
                'id' => $user->customer->id,
                'first_name' => $user->customer->first_name,
                'last_name' => $user->customer->last_name,
                'phone' => $user->customer->phone,
                'marketing_consent' => $user->customer->marketing_consent_at !== null,
            ] : null,
        ];
    }
}
