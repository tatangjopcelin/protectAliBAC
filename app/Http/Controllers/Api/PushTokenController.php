<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use App\Services\ApnService;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PushTokenController extends Controller
{
    /**
     * Enregistrer ou mettre à jour le token push de l'appareil (appelé par l'app mobile).
     * Body: { "token": "<device_token>", "platform": "ios" | "android" }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['required', 'string', Rule::in(['ios', 'android'])],
        ]);

        $user = $request->user();

        PushToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'token' => $validated['token'],
            ],
            ['platform' => $validated['platform']]
        );

        return response()->json(['message' => 'Token enregistré'], 201);
    }

    /**
     * Envoyer une notification de test à l'utilisateur connecté.
     * iOS (APNs) et Android (FCM) sont supportés.
     */
    public function sendTest(Request $request, ApnService $apn, FcmService $fcm): JsonResponse
    {
        $user = $request->user();
        $iosTokens = $user->pushTokens()->where('platform', 'ios')->get();
        $androidTokens = $user->pushTokens()->where('platform', 'android')->get();

        if ($iosTokens->isEmpty() && $androidTokens->isEmpty()) {
            return response()->json([
                'message' => 'Aucun appareil enregistré. Connectez-vous sur l\'app (iPhone ou Android), acceptez les notifications, puis réessayez.',
            ], 400);
        }

        $sent = 0;
        $errors = [];

        // Envoi aux appareils iOS (APNs)
        $apnConfigured = $this->isApnConfigured();
        foreach ($iosTokens as $pushToken) {
            if (! $apnConfigured) {
                $errors[] = 'APNs non configuré (iOS).';
                break;
            }
            try {
                if ($apn->send(
                    $pushToken->token,
                    'Brole',
                    'Ceci est une notification de test. Les push fonctionnent !',
                    ['route' => '/tabs/dashboard']
                )) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('Envoi notification de test iOS échoué', [
                    'token_id' => $pushToken->id,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = $e->getMessage();
            }
        }

        // Envoi aux appareils Android (FCM)
        if ($fcm->isConfigured()) {
            foreach ($androidTokens as $pushToken) {
                try {
                    if ($fcm->send(
                        $pushToken->token,
                        'Brole',
                        'Ceci est une notification de test. Les push fonctionnent !',
                        ['route' => '/tabs/dashboard']
                    )) {
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Envoi notification de test Android échoué', [
                        'token_id' => $pushToken->id,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = $e->getMessage();
                }
            }
        } elseif (! $androidTokens->isEmpty()) {
            $errors[] = 'FCM non configuré (Android). Définissez FCM_CREDENTIALS_JSON dans le .env.';
        }

        if ($sent === 0) {
            $message = 'Impossible d\'envoyer la notification.';
            if (! $apnConfigured && ! $iosTokens->isEmpty()) {
                $message .= ' APNs non configuré (.env : APN_KEY_PATH, APN_KEY_ID, APN_TEAM_ID, APN_BUNDLE_ID).';
            }
            if (! $fcm->isConfigured() && ! $androidTokens->isEmpty()) {
                $message .= ' FCM non configuré (.env : FCM_CREDENTIALS_JSON).';
            }

            return response()->json([
                'message' => trim($message),
                'errors' => array_slice($errors, 0, 3),
            ], 502);
        }

        return response()->json([
            'message' => $sent === 1
                ? 'Notification de test envoyée. Vérifiez votre téléphone.'
                : "{$sent} notifications de test envoyées. Vérifiez vos appareils.",
        ]);
    }

    private function isApnConfigured(): bool
    {
        $keyPath = config('services.apn.key_path');

        return $keyPath && is_file($keyPath)
            && config('services.apn.key_id')
            && config('services.apn.team_id')
            && config('services.apn.bundle_id');
    }
}
