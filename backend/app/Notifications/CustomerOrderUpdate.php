<?php

namespace App\Notifications;

use App\Domain\Shared\Money;
use App\Domain\Shared\Settings;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerOrderUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    /** Nouvelles tentatives espacées (audit M16) : une coupure SMTP passagère ne fait plus perdre l'email. */
    public int $tries = 5;

    /** @return list<int> secondes avant chaque nouvelle tentative */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    private const SUBJECTS = [
        'order.pending_confirmation' => 'Nous avons bien reçu votre commande',
        'order.accepted' => 'Votre commande est confirmée',
        'order.rejected' => 'Votre commande ne peut pas être honorée',
        'order.ready' => 'Votre commande est prête',
        'order.picked_up' => 'Votre commande est en route',
        'order.delivered' => 'Votre commande a été livrée',
        'order.cancelled' => 'Votre commande a été annulée',
        'order.delivery_failed' => 'Livraison non aboutie : nous vous recontactons',
        'payment.confirmed' => 'Paiement confirmé',
    ];

    public function __construct(private readonly Order $order, private readonly string $event)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    private function showCode(): bool
    {
        return in_array($this->event, ['order.pending_confirmation', 'order.accepted', 'order.ready', 'order.picked_up', 'payment.confirmed'], true)
            && $this->order->fulfillment_type === 'delivery' && $this->order->delivery_code
            && (bool) app(Settings::class)->get('delivery.code_enabled', false);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = self::SUBJECTS[$this->event] ?? 'Mise à jour de votre commande';

        return (new MailMessage)
            ->subject("MK Délices — {$subject} ({$this->order->reference})")
            ->greeting('Bonjour '.($this->order->customer_snapshot['first_name'] ?? '').',')
            ->line("{$subject}.")
            ->line("Référence : {$this->order->reference}")
            ->line('Montant total : '.Money::format($this->order->total_xof))
            ->lineIf($this->showCode(), "Code de remise à donner au livreur à la réception : {$this->order->delivery_code}")
            ->line('Conservez le lien de suivi reçu lors de votre commande pour suivre son avancement.')
            ->salutation('L\'équipe MK Délices');
    }
}
