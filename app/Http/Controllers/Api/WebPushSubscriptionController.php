<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPushSubscriptionController extends Controller
{
    public function publicKey(): JsonResponse
    {
        return response()->json([
            'publicKey' => (string) config('services.webpush.public_key', ''),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);
        $endpointHash = hash('sha256', $validated['endpoint']);

        $request->user()->webPushSubscriptions()->updateOrCreate(
            ['endpoint_hash' => $endpointHash],
            [
                'endpoint' => $validated['endpoint'],
                'endpoint_hash' => $endpointHash,
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? 'aesgcm',
                'user_agent' => (string) $request->userAgent(),
            ]
        );

        return response()->json(['message' => 'Subscription web push enregistrée'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);
        $endpointHash = hash('sha256', $validated['endpoint']);

        $request->user()
            ->webPushSubscriptions()
            ->where('endpoint_hash', $endpointHash)
            ->delete();

        return response()->json(['message' => 'Subscription web push supprimée']);
    }
}

