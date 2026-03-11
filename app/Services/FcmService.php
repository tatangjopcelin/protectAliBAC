<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

class FcmService
{
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var array{client_email?: string, private_key?: string, project_id?: string}|null */
    private ?array $credentials = null;

    private ?string $cachedAccessToken = null;

    private ?int $tokenExpiresAt = null;

    /**
     * Envoie une notification push à un appareil Android via FCM HTTP v1.
     *
     * @param  string  $deviceToken  Token FCM de l'appareil
     * @param  string  $title  Titre de la notification
     * @param  string  $body  Corps du message
     * @param  array<string, mixed>  $data  Données custom (ex. route pour deep link)
     * @return bool  True si envoyé avec succès
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        $credentials = $this->getCredentials();
        if ($credentials === null) {
            Log::warning('FCM non configuré (FCM_CREDENTIALS_JSON ou fichier manquant)');

            return false;
        }

        $projectId = $credentials['project_id'] ?? null;
        if (! $projectId) {
            Log::warning('FCM: project_id manquant dans le fichier credentials');

            return false;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $message = [
            'token' => trim($deviceToken),
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
        ];

        if (! empty($data)) {
            $message['data'] = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $data);
        }

        $body = ['message' => $message];

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        $headers = [
            'Authorization: Bearer '.$accessToken,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::warning('FCM curl error', ['error' => $error]);

            return false;
        }

        if ($httpCode !== 200) {
            Log::warning('FCM response error', ['code' => $httpCode, 'body' => $response]);

            return false;
        }

        return true;
    }

    /**
     * Indique si FCM est configuré (fichier credentials présent et valide).
     */
    public function isConfigured(): bool
    {
        return $this->getCredentials() !== null;
    }

    /**
     * @return array{client_email: string, private_key: string, project_id: string}|null
     */
    private function getCredentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = config('services.fcm.credentials_path');
        if (! $path) {
            return null;
        }
        // Chemin relatif au projet → convertir en chemin absolu
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)
            || empty($decoded['client_email'])
            || empty($decoded['private_key'])
            || empty($decoded['project_id'])) {
            Log::warning('FCM: fichier credentials invalide (client_email, private_key ou project_id manquant)');

            return null;
        }

        $this->credentials = [
            'client_email' => $decoded['client_email'],
            'private_key' => str_replace('\\n', "\n", $decoded['private_key']),
            'project_id' => $decoded['project_id'],
        ];

        return $this->credentials;
    }

    private function getAccessToken(): ?string
    {
        $now = time();
        if ($this->cachedAccessToken !== null && $this->tokenExpiresAt !== null && $now < $this->tokenExpiresAt - 60) {
            return $this->cachedAccessToken;
        }

        $credentials = $this->getCredentials();
        if ($credentials === null) {
            return null;
        }

        $jwt = $this->createOAuthJwt($credentials);
        if ($jwt === null) {
            return null;
        }

        $ch = curl_init(self::OAUTH_TOKEN_URL);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            Log::warning('FCM OAuth token error', ['code' => $httpCode, 'body' => $response, 'error' => $error]);

            return null;
        }

        $data = json_decode($response, true);
        if (! is_array($data) || empty($data['access_token'])) {
            return null;
        }

        $this->cachedAccessToken = $data['access_token'];
        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $this->tokenExpiresAt = $now + $expiresIn;

        return $this->cachedAccessToken;
    }

    /**
     * Crée un JWT pour l'authentification OAuth2 Google (service account).
     *
     * @param  array{client_email: string, private_key: string}  $credentials
     */
    private function createOAuthJwt(array $credentials): ?string
    {
        try {
            $key = InMemory::plainText($credentials['private_key']);
            $config = Configuration::forAsymmetricSigner(
                new Sha256,
                $key,
                $key
            );
            $now = new \DateTimeImmutable;
            $token = $config->builder()
                ->issuedBy($credentials['client_email'])
                ->relatedTo($credentials['client_email'])
                ->permittedFor('https://oauth2.googleapis.com/token')
                ->issuedAt($now)
                ->expiresAt($now->modify('+1 hour'))
                ->withClaim('scope', self::FCM_SCOPE)
                ->getToken($config->signer(), $config->signingKey());

            return $token->toString();
        } catch (\Throwable $e) {
            Log::warning('FCM JWT creation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
