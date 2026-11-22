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
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
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
        $data = $request->validate($this->credentialRules());
        $user = $this->checkCredentials($data, 'web');
        Auth::guard('web')->login($user, $request->boolean('remember'));
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', $user, null, null, $user->id);

        return response()->json(['data' => $this->present($user)]);
    }

    /**
     * Application mobile : échange identifiant (email ou téléphone) + mot de passe contre un jeton d'accès
     * propre à l'appareil (en-tête « Authorization: Bearer <jeton> »). Mêmes règles que la connexion web :
     * limitation des tentatives, compte actif obligatoire, journal d'audit. Le jeton n'est renvoyé qu'une fois.
     */
    public function issueToken(Request $request): JsonResponse
    {
        $data = $request->validate($this->credentialRules() + ['device_name' => ['required', 'string', 'min:2', 'max:100']]);
        $user = $this->checkCredentials($data, 'mobile');
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
        // Audit S-14 : email comparé en minuscules (sinon « Awa@… » passait la règle « unique » puis échouait en 500).
        self::lowercaseEmail($request);
        $data = $request->validate([
            // Renseigné par l'application mobile : un jeton est renvoyé au lieu d'ouvrir une session web.
            'device_name' => ['nullable', 'string', 'min:2', 'max:100'],
            'first_name' => ['required', 'string', 'min:2', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:160', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
            'marketing_consent' => ['nullable', 'boolean'],
            'accepted_privacy' => ['accepted'],
        ]);
        $phone = Phone::normalizeSenegal($data['phone']);
        if ($phone === null) {
            throw DomainException::unprocessable('invalid_phone', 'Numéro de téléphone sénégalais invalide.');
        }
        self::assertPhoneFree($phone);
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

    /**
     * Mot de passe oublié : un lien valable une heure est envoyé par email. Réponse identique que le compte
     * existe ou non (pas d'énumération des comptes) ; les demandes répétées sont limitées par le courtier.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:160']]);
        $email = mb_strtolower($data['email']);
        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        if ($user) {
            $status = Password::broker()->sendResetLink(['email' => $email]);
            $this->audit->log('auth.password_reset_requested', $user, null, ['sent' => $status === Password::RESET_LINK_SENT], $user->id);
        }

        return response()->json(['data' => [
            'message' => 'Si un compte correspond à cette adresse, un email avec un lien de réinitialisation vient de vous être envoyé. Pensez à vérifier vos courriers indésirables.',
        ]], 202);
    }

    /** Nouveau mot de passe à partir du lien reçu : tous les appareils connectés sont déconnectés. */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:160'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);
        // Audit S-15 : un membre de l'équipe garde la règle de l'équipe (12 caractères, majuscules et minuscules).
        $target = User::query()->where('email', mb_strtolower($data['email']))->first();
        if ($target?->isStaff()) {
            $request->validate(['password' => [self::staffPasswordRule()]]);
        }
        $status = Password::broker()->reset(
            ['email' => mb_strtolower($data['email']), 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) {
                // Le changement de mot de passe révoque les jetons mobiles (User::booted) ; les sessions web aussi.
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $this->audit->log('auth.password_reset', $user, null, null, $user->id);
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw DomainException::unprocessable('invalid_reset_link', 'Ce lien de réinitialisation est invalide ou a expiré. Faites une nouvelle demande.');
        }

        return response()->json(['data' => ['message' => 'Votre mot de passe a été modifié. Vous pouvez vous connecter.']]);
    }

    /** Règle des mots de passe de l'équipe (back-office), plus stricte que celle des clients. */
    public static function staffPasswordRule(): PasswordRule
    {
        return PasswordRule::min(12)->letters()->mixedCase()->numbers();
    }

    /** Email saisi passé en minuscules AVANT validation (règle « unique » insensible à la casse). */
    public static function lowercaseEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
    }

    /**
     * Identifiant de connexion sous une forme unique : email en minuscules, numéro sénégalais au format
     * +221…, sinon le texte saisi en minuscules. Sert à la limitation des tentatives par compte (audit S-10).
     */
    public static function normalizedIdentifier(mixed $raw): string
    {
        $identifier = mb_strtolower(trim(is_string($raw) ? $raw : ''));
        if ($identifier === '' || str_contains($identifier, '@')) {
            return $identifier;
        }

        return Phone::normalizeSenegal($identifier) ?? $identifier;
    }

    /** Un numéro de téléphone ne peut désigner qu'un seul compte (connexion par téléphone). */
    public static function assertPhoneFree(string $phone, ?string $exceptUserId = null): void
    {
        $taken = User::query()->where('phone', $phone)->whereNull('anonymized_at')
            ->when($exceptUserId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($taken) {
            throw DomainException::unprocessable('phone_taken', 'Ce numéro de téléphone est déjà associé à un compte. Connectez-vous ou utilisez « Mot de passe oublié ».');
        }
    }

    /** Identifiant de connexion : « login » (email ou téléphone), ou « email » / « phone » séparés. */
    private function credentialRules(): array
    {
        return [
            'login' => ['nullable', 'string', 'max:160', 'required_without_all:email,phone'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * Vérifie identifiant + mot de passe. Un hachage est toujours calculé, même si l'identifiant est inconnu :
     * temps de réponse identique (pas d'énumération des comptes).
     */
    private function checkCredentials(array $data, string $channel): User
    {
        $identifier = trim((string) ($data['login'] ?? $data['email'] ?? $data['phone'] ?? ''));
        $byEmail = str_contains($identifier, '@');
        $phone = $byEmail ? null : Phone::normalizeSenegal($identifier);
        $user = match (true) {
            $byEmail => User::query()->where('email', mb_strtolower($identifier))->first(),
            // Numéro unique (index partiel, audit S-13) ; tri conservé par prudence, comptes jamais connectés en dernier.
            $phone !== null => User::query()->where('phone', $phone)->whereNull('anonymized_at')->orderByRaw('last_login_at desc nulls last')->first(),
            default => null,
        };
        if (! Hash::check($data['password'], $user?->password ?? self::DUMMY_HASH) || $user === null) {
            $this->audit->log('auth.login_failed', null, null, [
                // Audit S-16 : empreinte à clé secrète (un simple SHA-256 d'un numéro se retrouve par essais).
                'identifier_hash' => hash_hmac('sha256', self::normalizedIdentifier($identifier), (string) config('app.key')),
                'method' => $byEmail ? 'email' : 'phone',
                'channel' => $channel,
            ]);

            throw new DomainException('invalid_credentials', $byEmail || $phone !== null
                ? 'Identifiants incorrects.'
                : 'Saisissez votre adresse email ou votre numéro de téléphone (ex. 77 123 45 67).', 401);
        }
        if (! $user->is_active) {
            throw new DomainException('account_disabled', 'Ce compte est désactivé.', 403);
        }

        return $user;
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
