<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Policies\AlertPolicy;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || !(new AlertPolicy())->viewAny($user)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        
        $query = Alert::with('product');

        if ($request->has('is_read')) {
            $query->where('is_read', $request->boolean('is_read'));
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function unread(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $alerts = Alert::with('product')
            ->where('is_read', false)
            ->orderBy('severity', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($alerts);
    }

    public function markAsRead(string $id, Request $request)
    {
        $alert = Alert::findOrFail($id);
        $user = $request->user();
        
        if (!$user || !(new AlertPolicy())->markAsRead($user, $alert)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        
        $alert->markAsRead();

        return response()->json($alert->fresh());
    }

    public function show(string $id)
    {
        return response()->json(Alert::with('product')->findOrFail($id));
    }
}
