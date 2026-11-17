<?php

namespace App\Domain\Deliveries\Push;

use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Envoi via le protocole Web Push (RFC 8030/8291) signé VAPID, avec la bibliothèque minishlink/web-push. */
class WebPushTransport implements PushTransport
{
    public function send(iterable $subscriptions, array $payload, int $ttlSeconds): array
    {
        $webPush = new WebPush(
            ['VAPID' => [
                'subject' => (string) config('mkdelices.push.subject'),
                'publicKey' => (string) config('mkdelices.push.vapid_public_key'),
                'privateKey' => (string) config('mkdelices.push.vapid_private_key'),
            ]],
            ['TTL' => $ttlSeconds, 'urgency' => 'high'],
            10,
        );
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(new Subscription($sub->endpoint, $sub->p256dh, $sub->auth, ContentEncoding::aes128gcm), $body);
        }

        $results = [];
        foreach ($webPush->flush() as $report) {
            $results[$report->getEndpoint()] = $report->isSuccess() ? 'sent' : ($report->isSubscriptionExpired() ? 'expired' : 'failed');
        }

        return $results;
    }
}
