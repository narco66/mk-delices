<?php

namespace App\Domain\Customers\Push;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging, API HTTP v1 (Android et iOS via APNs). Authentification par compte de service
 * Google : jeton OAuth2 signé localement (RS256), mis en cache 50 minutes. Aucune dépendance supplémentaire.
 *
 * Configuration (.env du serveur uniquement, jamais dans le dépôt) :
 *   FCM_PROJECT_ID=<identifiant du projet Firebase>
 *   FCM_CREDENTIALS=/etc/mk-delices/firebase-service-account.json
 */
class FcmTransport implements MobilePushTransport
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function isConfigured(): bool
    {
        $path = (string) config('mkdelices.push.fcm.credentials');

        return filled(config('mkdelices.push.fcm.project_id')) && $path !== '' && is_readable($path);
    }

    public function send(iterable $devices, array $message): array
    {
        $results = [];
        if (! $this->isConfigured()) {
            return $results;
        }
        $url = 'https://fcm.googleapis.com/v1/projects/'.config('mkdelices.push.fcm.project_id').'/messages:send';
        $accessToken = $this->accessToken();
        foreach ($devices as $device) {
            $response = Http::withToken($accessToken)->timeout(10)->acceptJson()->post($url, ['message' => [
                'token' => $device->token,
                'notification' => ['title' => $message['title'], 'body' => $message['body']],
                // Données de navigation lues par l'application (toutes les valeurs doivent être des chaînes).
                'data' => array_map('strval', $message['data']),
                'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'orders']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]]);
            $results[$device->id] = $this->outcome($response);
        }

        return $results;
    }

    /** @return 'sent'|'expired'|'failed' */
    private function outcome(Response $response): string
    {
        if ($response->successful()) {
            return 'sent';
        }
        $code = (string) $response->json('error.details.0.errorCode', $response->json('error.status', ''));
        // Application désinstallée ou jeton périmé : l'appareil est retiré pour ne plus être tenté.
        if ($response->status() === 404 || $code === 'UNREGISTERED' || ($response->status() === 400 && $code === 'INVALID_ARGUMENT')) {
            return 'expired';
        }
        Log::warning('push.fcm_failed', ['status' => $response->status(), 'code' => $code]);

        return 'failed';
    }

    private function accessToken(): string
    {
        return Cache::remember('mk:fcm:access_token', now()->addMinutes(50), function () {
            $account = json_decode((string) file_get_contents((string) config('mkdelices.push.fcm.credentials')), true);
            if (! is_array($account) || empty($account['client_email']) || empty($account['private_key'])) {
                throw new RuntimeException('Fichier de compte de service Firebase invalide.');
            }
            $now = time();
            $segments = [
                $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64Url(json_encode([
                    'iss' => $account['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Signature du jeton Firebase impossible.');
            }
            $segments[] = $this->base64Url($signature);

            return Http::asForm()->timeout(10)->post($account['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
