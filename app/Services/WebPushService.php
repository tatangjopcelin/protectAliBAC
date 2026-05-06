<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function isConfigured(): bool
    {
        return !empty(config('services.webpush.public_key'))
            && !empty(config('services.webpush.private_key'))
            && !empty(config('services.webpush.subject'));
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $subscriptions = $user->webPushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'badge_count' => $data['badge_count'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->public_key,
                    'authToken' => $subscription->auth_token,
                    'contentEncoding' => $subscription->content_encoding ?: 'aesgcm',
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = (string) $report->getRequest()->getUri();
            if (!$report->isSuccess()) {
                Log::warning('Web push échoué', [
                    'endpoint' => $endpoint,
                    'reason' => $report->getReason(),
                    'user_id' => $user->id,
                ]);

                // Supprime les subscriptions expirées/invalides pour éviter les erreurs répétées.
                WebPushSubscription::where('endpoint', $endpoint)->delete();
            }
        }
    }
}

