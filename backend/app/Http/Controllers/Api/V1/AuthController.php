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
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;

/**
 * Authentification par session Sanctum (cookies HttpOnly + CSRF), aucun jeton en localStorage (TRD §5).
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

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
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
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function logout(Request $request): Response
    {
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
