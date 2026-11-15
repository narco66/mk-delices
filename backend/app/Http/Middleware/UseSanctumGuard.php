<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requête de l'application mobile (en-tête « Authorization: Bearer ») : l'utilisateur courant est résolu par
 * le jeton, y compris sur les routes publiques (panier, commande, suivi), qui rattachent ainsi les actions
 * d'un client mobile connecté à son compte. Les requêtes du site web (session) ne sont pas touchées : leur
 * garde reste « web », comportement inchangé. N'accorde aucun accès : les routes privées restent protégées
 * par auth:sanctum + permissions.
 */
class UseSanctumGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            auth()->shouldUse('sanctum');
        }

        return $next($request);
    }
}
