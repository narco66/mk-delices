<?php

namespace App\Domain\Orders;

use App\Domain\Shared\Phone;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\User;
use App\Notifications\CustomerOrderUpdate;
use App\Notifications\StaffOrderActivity;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Notifications transactionnelles avec anti-duplication (dedupe_key unique) et journal d'envoi.
 * Canal client : email si fourni. WhatsApp/SMS automatisés : non configurés (décision D-NOT-01).
 */
class OrderNotifier
{
    /** Rôles à prévenir selon le nouvel état. */
    private const STAFF_ROUTING = [
        'pending_confirmation' => ['commercial', 'admin'],
        'accepted' => ['preparateur'],
        'ready' => ['resp_livraison'],
        'delivery_failed' => ['resp_livraison', 'commercial'],
        'cancelled' => ['commercial'],
    ];

    /** États communiqués au client. */
    private const CUSTOMER_EVENTS = ['pending_confirmation', 'accepted', 'rejected', 'ready', 'picked_up', 'delivered', 'cancelled', 'delivery_failed'];

    public function orderStatusChanged(Order $order, OrderStatus $status): void
    {
        if ($order->is_test) {
            return;
        }
        $roles = self::STAFF_ROUTING[$status->value] ?? [];
        if ($roles !== [] && $this->claim($order, "staff:{$status->value}", 'database', null)) {
            $staff = User::role($roles)->where('is_active', true)->get();
            Notification::send($staff, new StaffOrderActivity($order, "order.{$status->value}"));
        }
        if (in_array($status->value, self::CUSTOMER_EVENTS, true)) {
            $this->notifyCustomer($order, "order.{$status->value}");
        }
    }

    public function paymentConfirmed(Order $order): void
    {
        if ($order->is_test) {
            return;
        }
        if ($this->claim($order, 'staff:payment.confirmed', 'database', null)) {
            Notification::send(User::role(['commercial', 'admin'])->where('is_active', true)->get(), new StaffOrderActivity($order, 'payment.confirmed'));
        }
        $this->notifyCustomer($order, 'payment.confirmed');
    }

    private function notifyCustomer(Order $order, string $event): void
    {
        $email = $order->customer_snapshot['email'] ?? null;
        if (! $email) {
            $this->claim($order, "customer:{$event}", 'none', Phone::mask($order->customer_snapshot['phone'] ?? null), 'skipped');

            return;
        }
        if ($this->claim($order, "customer:{$event}", 'mail', preg_replace('/(?<=.).(?=[^@]*@)/', '*', $email))) {
            Notification::route('mail', $email)->notify(new CustomerOrderUpdate($order, $event));
        }
    }

    /** Réserve l'envoi : renvoie false si déjà envoyé (anti-duplication). */
    private function claim(Order $order, string $event, string $channel, ?string $recipientMasked, string $status = 'sent'): bool
    {
        $inserted = NotificationLog::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'recipient_masked' => $recipientMasked,
            'event' => $event,
            'channel' => $channel,
            'dedupe_key' => "order:{$order->id}:{$event}",
            'delivery_status' => $status,
            'sent_at' => $status === 'sent' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1;
    }
}
