<?php

namespace Tests\Feature;

use App\Domain\Deliveries\Push\CourierPushNotifier;
use App\Domain\Deliveries\Push\PushTransport;
use App\Jobs\NotifyCourierOfMission;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Notifications du navigateur aux livreurs (décision du 17/11/2026). */
class CourierPushTest extends TestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    private const KEYS = ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['mkdelices.push.vapid_public_key' => 'test-public', 'mkdelices.push.vapid_private_key' => 'test-private']);
    }

    public function test_courier_subscribes_and_overview_exposes_public_key(): void
    {
        $courier = $this->partner($this->zone())->user;
        $this->actingAs($courier)->getJson('/api/v1/courier/missions')->assertOk()->assertJsonPath('data.push_public_key', 'test-public');

        $this->postJson('/api/v1/courier/push-subscriptions', ['endpoint' => self::ENDPOINT, 'keys' => self::KEYS])->assertCreated();
        // Ré-abonnement du même appareil : pas de doublon.
        $this->postJson('/api/v1/courier/push-subscriptions', ['endpoint' => self::ENDPOINT, 'keys' => self::KEYS])->assertCreated();
        $this->assertSame(1, PushSubscription::query()->where('user_id', $courier->id)->count());

        $this->deleteJson('/api/v1/courier/push-subscriptions', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_unknown_push_service_and_non_courier_are_refused(): void
    {
        $courier = $this->partner($this->zone())->user;
        foreach (['https://evil.example.com/push', 'http://fcm.googleapis.com/fcm/send/x', 'https://fcm.googleapis.com:8443/x', 'https://127.0.0.1/x'] as $endpoint) {
            $this->actingAs($courier)->postJson('/api/v1/courier/push-subscriptions', ['endpoint' => $endpoint, 'keys' => self::KEYS])
                ->assertStatus(422)->assertJsonValidationErrors('endpoint', 'error.details');
        }
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->postJson('/api/v1/courier/push-subscriptions', ['endpoint' => self::ENDPOINT, 'keys' => self::KEYS])
            ->assertForbidden();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_subscription_is_refused_when_push_is_not_configured(): void
    {
        config(['mkdelices.push.vapid_public_key' => null]);
        $courier = $this->partner($this->zone())->user;
        $this->actingAs($courier)->getJson('/api/v1/courier/missions')->assertJsonPath('data.push_public_key', null);
        $this->postJson('/api/v1/courier/push-subscriptions', ['endpoint' => self::ENDPOINT, 'keys' => self::KEYS])
            ->assertStatus(409)->assertJsonPath('error.code', 'push_disabled');
    }

    public function test_assignment_queues_notification_after_commit(): void
    {
        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        Queue::fake();
        $id = $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))
            ->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->assertCreated()->json('data.id');

        Queue::assertPushed(NotifyCourierOfMission::class, fn ($job) => $job->assignmentId === $id);
    }

    public function test_job_sends_minimal_payload_and_prunes_expired_subscriptions(): void
    {
        $fake = new class implements PushTransport
        {
            public array $calls = [];

            public function send(iterable $subscriptions, array $payload, int $ttlSeconds): array
            {
                $this->calls[] = $payload;
                $results = [];
                foreach ($subscriptions as $sub) {
                    $results[$sub->endpoint] = str_contains($sub->endpoint, 'expired') ? 'expired' : 'sent';
                }

                return $results;
            }
        };
        $this->app->instance(PushTransport::class, $fake);

        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        $courier = $partner->user;
        PushSubscription::query()->create(['user_id' => $courier->id, 'endpoint' => self::ENDPOINT, ...self::KEYS]);
        PushSubscription::query()->create(['user_id' => $courier->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/expired', ...self::KEYS]);

        // File synchrone en test : l'envoi a lieu à la validation de l'affectation.
        $id = $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))
            ->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->assertCreated()->json('data.id');

        $this->assertCount(1, $fake->calls);
        $payload = $fake->calls[0];
        $this->assertSame("/livreur/missions/{$id}", $payload['url']);
        $this->assertStringContainsString($order->reference, $payload['body']);
        // Ni téléphone ni nom du client dans la notification.
        $this->assertStringNotContainsString('+221', json_encode($payload));
        $this->assertSame([self::ENDPOINT], PushSubscription::query()->pluck('endpoint')->all());
        $this->assertNotNull(PushSubscription::query()->first()->last_success_at);
    }

    public function test_job_skips_mission_no_longer_proposed(): void
    {
        $fake = new class implements PushTransport
        {
            public int $count = 0;

            public function send(iterable $subscriptions, array $payload, int $ttlSeconds): array
            {
                $this->count++;

                return [];
            }
        };
        $this->app->instance(PushTransport::class, $fake);

        $zone = $this->zone();
        $order = $this->readyOrder($zone);
        $partner = $this->partner($zone);
        Queue::fake();
        PushSubscription::query()->create(['user_id' => $partner->user_id, 'endpoint' => self::ENDPOINT, ...self::KEYS]);
        $id = $this->actingAs($this->staff(User::ROLE_RESP_LIVRAISON))
            ->postJson('/api/v1/admin/delivery/assignments', ['order_id' => $order->id, 'partner_id' => $partner->id])->json('data.id');
        $this->actingAs($partner->user)->postJson("/api/v1/courier/missions/{$id}/actions", ['action' => 'accept'])->assertOk();

        (new NotifyCourierOfMission($id))->handle(app(CourierPushNotifier::class));
        $this->assertSame(0, $fake->count);
    }
}
