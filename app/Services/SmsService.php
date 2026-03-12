<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class SmsService
{
    private ?Client $client = null;

    public function isConfigured(): bool
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        return ! empty($sid) && ! empty($token) && ! empty($from);
    }

    /**
     * Formate un numéro français en E.164 pour Twilio (+33...).
     * Accepte : 0612345678, 06 12 34 56 78, +33612345678, 33612345678.
     */
    public function toE164France(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 9 && in_array($digits[0], ['6', '7'], true)) {
            return '+33' . $digits;
        }
        if (strlen($digits) === 10 && $digits[0] === '0' && in_array($digits[1], ['6', '7'], true)) {
            return '+33' . substr($digits, 1);
        }
        if (strlen($digits) === 11 && substr($digits, 0, 2) === '33') {
            return '+' . $digits;
        }

        return null;
    }

    /**
     * Envoie un SMS à un numéro (France, format E.164).
     * Le message est tronqué à 1600 caractères (multi-segments).
     */
    public function send(string $toE164, string $message): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('SMS non configuré (TWILIO_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM)');

            return false;
        }

        $message = mb_substr($message, 0, 1600);

        try {
            $client = $this->getClient();
            $client->messages->create($toE164, [
                'from' => config('services.twilio.from'),
                'body' => $message,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Envoi SMS échoué', [
                'to' => $toE164,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client(
                config('services.twilio.sid'),
                config('services.twilio.token')
            );
        }

        return $this->client;
    }
}
