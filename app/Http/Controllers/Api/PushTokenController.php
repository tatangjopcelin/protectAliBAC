<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use App\Services\ApnService;
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
     * Seuls les appareils iOS sont supportés (APNs). Android (FCM) à venir.
     */
    public function sendTest(Request $request, ApnService $apn): JsonResponse
    {
        $user = $request->user();
        $tokens = $user->pushTokens()->where('platform', 'ios')->get();
        $androidCount = $user->pushTokens()->where('platform', 'android')->count();

        if ($tokens->isEmpty()) {
            $message = $androidCount > 0
                ? 'Le test de notification est disponible uniquement sur iPhone pour le moment.'
                : 'Aucun appareil iOS enregistré. Connectez-vous sur l\'app iPhone, acceptez les notifications, puis réessayez.';
            return response()->json(['message' => $message], 400);
        }

        $keyPath = config('services.apn.key_path');
        if (! $keyPath || ! is_file($keyPath) || ! config('services.apn.key_id') || ! config('services.apn.team_id') || ! config('services.apn.bundle_id')) {
            return response()->json([
                'message' => 'APNs non configuré côté serveur. Définissez APN_KEY_ID, APN_TEAM_ID, APN_BUNDLE_ID et APN_KEY_PATH (fichier .p8) dans le .env du backend.',
            ], 503);
        }

        $sent = 0;
        $errors = [];

        foreach ($tokens as $pushToken) {
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
                Log::warning('Envoi notification de test échoué', [
                    'token_id' => $pushToken->id,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = $e->getMessage();
            }
        }

        if ($sent === 0) {
            return response()->json([
                'message' => 'Impossible d\'envoyer la notification. Vérifiez la config APNs (.env : APN_KEY_PATH, APN_KEY_ID, APN_TEAM_ID, APN_BUNDLE_ID).',
                'errors' => array_slice($errors, 0, 3),
            ], 502);
        }

        return response()->json([
            'message' => $sent === 1
                ? 'Notification de test envoyée. Vérifiez votre téléphone.'
                : "{$sent} notifications de test envoyées. Vérifiez vos appareils.",
        ]);
    }
}
