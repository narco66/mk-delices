<?php

namespace App\Http\Middleware;

use App\Domain\Shared\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestContext::set($request->header('X-Request-Id'));
        Log::withContext(['request_id' => RequestContext::id()]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', RequestContext::id());

        return $response;
    }

    public function terminate(): void
    {
        RequestContext::reset();
    }
}
