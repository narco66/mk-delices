<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** En-têtes de sécurité (SEC-012). La CSP stricte s'applique aux réponses API ; le SPA a sa propre CSP. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
        if ($request->is('api/*')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            // Symfony pose « no-cache, private » par défaut : on impose « no-store » (données personnelles), sauf si
            // le contrôleur a explicitement autorisé une mise en cache (public / max-age).
            $current = (string) $headers->get('Cache-Control');
            if (! str_contains($current, 'public') && ! str_contains($current, 'max-age')) {
                $headers->set('Cache-Control', 'no-store, private');
            }
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
