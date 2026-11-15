<?php

namespace App\Domain\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\Request;

/**
 * Contrat d'adaptation PSP (TRD §6) : createSession, fetchStatus, refund, validateWebhook.
 */
interface PaymentGateway
{
    public function code(): string;

    public function label(): string;

    /** Vrai seulement si les identifiants marchands nécessaires sont présents. */
    public function isConfigured(): bool;

    public function createSession(Payment $payment, Order $order): GatewaySession;

    /** Consultation serveur-à-serveur de l'état réel chez le prestataire. */
    public function fetchStatus(Payment $payment): GatewayStatus;

    /** @return string|null référence du remboursement chez le prestataire, null si exécution manuelle requise */
    public function refund(Refund $refund, Payment $payment): ?string;

    /**
     * Authentifie la requête webhook et en extrait l'événement.
     *
     * @throws InvalidWebhookSignature
     */
    public function validateWebhook(Request $request): WebhookEvent;
}
