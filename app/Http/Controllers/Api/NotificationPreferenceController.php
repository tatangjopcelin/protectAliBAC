<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request)
    {
        $preferences = NotificationPreference::where('user_id', $request->user()?->id)->get();
        return response()->json($preferences);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'channel' => 'required|string',
            'push_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'severity_level' => 'required|in:all,warning,critical',
        ]);

        $validated['user_id'] = $request->user()?->id;

        $preference = NotificationPreference::updateOrCreate(
            ['user_id' => $validated['user_id'], 'channel' => $validated['channel']],
            $validated
        );

        return response()->json($preference, 201);
    }

    public function update(Request $request, string $id)
    {
        $preference = NotificationPreference::where('user_id', $request->user()?->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'push_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'severity_level' => 'in:all,warning,critical',
        ]);

        $preference->update($validated);

        return response()->json($preference);
    }
}
