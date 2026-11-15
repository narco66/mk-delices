<?php

namespace App\Http\Middleware;

use App\Domain\Shared\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Un compte désactivé perd immédiatement l'accès, même avec une session ouverte. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            // Invalidation de la session : le compte désactivé est déconnecté.
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            throw new DomainException('account_disabled', 'Ce compte est désactivé.', 403);
        }

        return $next($request);
    }
}
