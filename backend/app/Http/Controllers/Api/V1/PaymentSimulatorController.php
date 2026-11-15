<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Gateways\GatewayUnavailable;
use App\Domain\Payments\Gateways\SimulatorGateway;
use App\Domain\Payments\PaymentService;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Écran « prestataire » simulé (local/testing uniquement). Il émet un webhook SIGNÉ qui suit
 * exactement le même chemin de vérification qu'un webhook réel : aucun raccourci de confirmation.
 */
class PaymentSimulatorController extends Controller
{
    public function __construct(private readonly SimulatorGateway $simulator)
    {
        if (! $this->simulator->isConfigured()) {
            abort(404);
        }
    }

    public function show(string $reference): JsonResponse
    {
        $payment = Payment::query()->with('order')->where('provider', 'simulator')->where('provider_reference', $reference)->firstOrFail();

        return response()->json(['data' => [
            'reference' => $reference,
            'order_reference' => $payment->order->reference,
            'amount_xof' => $payment->amount_xof,
            'status' => $payment->status->value,
            'notice' => 'Environnement de test : aucun débit réel.',
        ]]);
    }

    public function complete(Request $request, string $reference, PaymentService $payments): JsonResponse
    {
        $data = $request->validate(['outcome' => ['required', 'in:success,failure']]);
        Payment::query()->where('provider', 'simulator')->where('provider_reference', $reference)->firstOrFail();
        try {
            $event = $this->simulator->completeSession($reference, $data['outcome']);
        } catch (GatewayUnavailable) {
            throw new DomainException('not_found', 'Session simulée inconnue.', 404);
        }
        $body = json_encode($event);
        $webhook = Request::create('/api/v1/webhooks/payments/simulator', 'POST', server: [
            'HTTP_'.strtoupper(str_replace('-', '_', SimulatorGateway::SIGNATURE_HEADER)) => $this->simulator->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
        $result = $payments->receiveWebhook('simulator', $webhook);

        return response()->json(['data' => ['webhook' => $result['status'], 'outcome' => $data['outcome']]]);
    }
}
