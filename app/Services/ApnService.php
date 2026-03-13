<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class ApnService
{
    /**
     * Envoie une notification push à un device iOS via APNs.
     *
     * @param  string  $deviceToken  Token de l'appareil (hex)
     * @param  string  $title  Titre de la notification
     * @param  string  $body  Corps du message
     * @param  array<string, mixed>  $data  Données custom (ex. route pour deep link)
     * @return bool  True si envoyé avec succès
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        $keyPath = config('services.apn.key_path');
        $keyId = config('services.apn.key_id');
        $teamId = config('services.apn.team_id');
        $bundleId = config('services.apn.bundle_id');
        $sandbox = config('services.apn.sandbox', true);

        if (! $keyPath || ! is_file($keyPath) || ! $keyId || ! $teamId || ! $bundleId) {
            Log::warning('APNs non configuré (APN_KEY_PATH, APN_KEY_ID, APN_TEAM_ID, APN_BUNDLE_ID)');
            return false;
        }

        $jwt = $this->createToken($keyPath, $keyId, $teamId);
        if ($jwt === null) {
            return false;
        }

        $host = $sandbox ? 'api.sandbox.push.apple.com' : 'api.push.apple.com';
        $url = "https://{$host}/3/device/".trim($deviceToken);

        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'sound' => 'default',
            ],
        ];
        if (! empty($data)) {
            $payload['data'] = $data;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        $headers = [
            'authorization: bearer '.$jwt,
            'apns-topic: '.$bundleId,
            'apns-push-type: alert',
            'content-type: application/json',
        ];
        // Regroupement / remplacement des notifications (comme le tag FCM sur Android).
        // Quand APNs sera configuré, les notifications avec le même collapse-id s'afficheront en une seule.
        $collapseId = isset($data['tag']) && $data['tag'] !== '' ? (string) $data['tag'] : null;
        if ($collapseId !== null) {
            $headers[] = 'apns-collapse-id: '.$collapseId;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::warning('APNs curl error', ['error' => $error]);
            return false;
        }

        if ($httpCode !== 200) {
            Log::warning('APNs response error', ['code' => $httpCode, 'body' => $response]);
            return false;
        }

        return true;
    }

    /**
     * Crée un JWT pour l'authentification APNs (clé .p8, ES256).
     */
    private function createToken(string $keyPath, string $keyId, string $teamId): ?string
    {
        try {
            $key = InMemory::file($keyPath);
            $config = Configuration::forAsymmetricSigner(
                new Sha256,
                $key,
                $key
            );
            $now = new \DateTimeImmutable;
            $token = $config->builder()
                ->issuedBy($teamId)
                ->issuedAt($now)
                ->withHeader('kid', $keyId)
                ->getToken($config->signer(), $config->signingKey());

            return $token->toString();
        } catch (\Throwable $e) {
            Log::warning('APNs JWT creation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
