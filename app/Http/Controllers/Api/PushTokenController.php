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
     * Envoyer une notification de test à l'utilisateur connecté (tous ses appareils enregistrés).
     */
    public function sendTest(Request $request, ApnService $apn): JsonResponse
    {
        $user = $request->user();
        $tokens = $user->pushTokens()->where('platform', 'ios')->get();

        if ($tokens->isEmpty()) {
            return response()->json([
                'message' => 'Aucun appareil enregistré. Connectez-vous sur l’app mobile et acceptez les notifications.',
            ], 400);
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
                'message' => 'Impossible d’envoyer la notification. Vérifiez la config APNs (.env : APN_KEY_PATH, APN_KEY_ID, APN_TEAM_ID, APN_BUNDLE_ID).',
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
