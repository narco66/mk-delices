<?php

use App\Domain\Shared\DomainException;
use App\Domain\Shared\RequestContext;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseSanctumGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Format d'erreur stable (API-CONTRACT §1) : { error: { code, message, details }, request_id }. */
$error = fn (string $code, string $message, int $status, array $details = []) => response()->json([
    'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details],
    'request_id' => RequestContext::id(),
], $status, ['X-Request-Id' => RequestContext::id()]);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Audit sécurité du 18/11/2026 : pas de redirection vers une route « login » inexistante (erreur 500)
        // quand un client de l'API n'envoie pas « Accept: application/json » ; l'API répond 401.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/connexion');
        $middleware->api(append: [UseSanctumGuard::class]);
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->dontReport([DomainException::class]);

        $exceptions->render(fn (DomainException $e) => $error($e->errorCode, $e->getMessage(), $e->status, $e->details));
        $exceptions->render(function (ValidationException $e, Request $request) use ($error) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $error('validation_failed', 'Certaines informations sont invalides.', 422, $e->errors());
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($error) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $error('unauthenticated', 'Authentification requise.', 401);
            }
        });
        $exceptions->render(function (AuthorizationException|UnauthorizedException $e, Request $request) use ($error) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $error('forbidden', 'Action non autorisée.', 403);
            }
        });
        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($error) {
            return $error('csrf_mismatch', 'Session expirée, veuillez recharger la page.', 419);
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($error) {
            return $error('too_many_requests', 'Trop de requêtes, veuillez patienter.', 429, ['retry_after' => $e->getHeaders()['Retry-After'] ?? null]);
        });
        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) use ($error) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $error('not_found', 'Ressource introuvable.', 404);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($error) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            $status = $e->getStatusCode();
            // Gestion des erreurs (20/11/2026) : jamais le message brut d'une exception du framework (anglais,
            // détails internes : routes, méthodes…). Seul un abort(4xx, 'message') écrit dans le code est conservé.
            [$code, $message] = match ($status) {
                400 => ['bad_request', 'La demande est invalide.'],
                403 => ['forbidden', 'Action non autorisée.'],
                405 => ['method_not_allowed', 'Cette opération n’est pas disponible.'],
                409 => ['conflict', 'Cette opération est en conflit avec l’état actuel. Rechargez la page.'],
                413 => ['payload_too_large', 'Le fichier ou les données envoyés sont trop volumineux.'],
                415 => ['unsupported_media_type', 'Ce type de contenu n’est pas accepté.'],
                422 => ['validation_failed', 'Certaines informations sont invalides.'],
                503 => ['maintenance', 'Le site est en cours de mise à jour. Réessayez dans quelques instants.'],
                default => $status >= 500 ? ['server_error', 'Le service est momentanément indisponible. Réessayez dans quelques instants.']
                    : ['http_error', 'La demande a été refusée.'],
            };
            if ($status < 500 && get_class($e) === HttpException::class && $e->getMessage() !== '') {
                $message = $e->getMessage();
            }
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            return $error($code, $message, $status, $retryAfter !== null ? ['retry_after' => (int) $retryAfter] : [])
                ->withHeaders($retryAfter !== null ? ['Retry-After' => $retryAfter] : []);
        });
        $exceptions->render(function (Throwable $e, Request $request) use ($error) {
            if (($request->is('api/*') || $request->expectsJson()) && ! config('app.debug')) {
                return $error('server_error', 'Une erreur inattendue est survenue. Référence : '.RequestContext::id(), 500);
            }
        });
    })->create();
