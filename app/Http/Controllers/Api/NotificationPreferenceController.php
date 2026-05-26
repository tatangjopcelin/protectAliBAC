<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    /** Canaux disponibles avec leurs valeurs par défaut */
    public const CHANNELS = [
        'product_created'   => ['email' => false, 'sms' => false],
        'product_updated'   => ['email' => false, 'sms' => false],
        'product_deleted'   => ['email' => false, 'sms' => false],
        'product_expiring'  => ['email' => false, 'sms' => false],
        'product_expired'   => ['email' => false, 'sms' => false],
        'product_stock'     => ['email' => false, 'sms' => false],
        'task_assigned'     => ['email' => true,  'sms' => false],
        'schedule_published'=> ['email' => true,  'sms' => false],
        'shopping_list'     => ['email' => false, 'sms' => false],
    ];

    public function index(Request $request)
    {
        $userId = $request->user()?->id;
        $saved  = NotificationPreference::where('user_id', $userId)
            ->get()
            ->keyBy('channel');

        // Retourner tous les canaux, avec les valeurs par défaut spécifiques à chaque canal
        $result = array_map(function (string $channel, array $defaults) use ($saved, $userId) {
            if ($saved->has($channel)) {
                return $saved[$channel];
            }
            return [
                'id'              => null,
                'user_id'         => $userId,
                'channel'         => $channel,
                'email_enabled'   => $defaults['email'],
                'sms_enabled'     => $defaults['sms'],
                'push_enabled'    => true,
                'severity_level'  => 'all',
            ];
        }, array_keys(self::CHANNELS), array_values(self::CHANNELS));

        return response()->json(array_values($result));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'channel'       => 'required|string',
            'push_enabled'  => 'boolean',
            'email_enabled' => 'boolean',
            'sms_enabled'   => 'boolean',
            'severity_level' => 'in:all,warning,critical',
        ]);

        $validated['user_id'] = $request->user()?->id;

        $preference = NotificationPreference::updateOrCreate(
            ['user_id' => $validated['user_id'], 'channel' => $validated['channel']],
            $validated
        );

        return response()->json($preference, 201);
    }

    /**
     * Sauvegarde en lot toutes les préférences d'un coup (1 seul appel depuis le frontend).
     */
    public function batchUpsert(Request $request)
    {
        $validated = $request->validate([
            'preferences'                  => 'required|array',
            'preferences.*.channel'        => 'required|string',
            'preferences.*.email_enabled'  => 'boolean',
            'preferences.*.sms_enabled'    => 'boolean',
            'preferences.*.push_enabled'   => 'boolean',
            'preferences.*.severity_level' => 'in:all,warning,critical',
        ]);

        $userId = $request->user()?->id;

        foreach ($validated['preferences'] as $pref) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $userId, 'channel' => $pref['channel']],
                [
                    'email_enabled'  => $pref['email_enabled']  ?? true,
                    'sms_enabled'    => $pref['sms_enabled']    ?? false,
                    'push_enabled'   => $pref['push_enabled']   ?? true,
                    'severity_level' => $pref['severity_level'] ?? 'all',
                ]
            );
        }

        return $this->index($request);
    }

    public function update(Request $request, string $id)
    {
        $preference = NotificationPreference::where('user_id', $request->user()?->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'push_enabled'   => 'boolean',
            'email_enabled'  => 'boolean',
            'sms_enabled'    => 'boolean',
            'severity_level' => 'in:all,warning,critical',
        ]);

        $preference->update($validated);

        return response()->json($preference);
    }
}
