<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\PaymentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    /** Réponse rapide 2xx après authentification et déduplication ; traitement en file d'attente. */
    public function __invoke(Request $request, string $provider, PaymentService $payments): JsonResponse
    {
        $result = $payments->receiveWebhook($provider, $request);

        return response()->json(['received' => true, 'status' => $result['status']]);
    }
}
