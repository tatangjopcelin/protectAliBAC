<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DemoRequestReceived;
use App\Models\DemoRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DemoRequestController extends Controller
{
    /**
     * Endpoint public: enregistre la demande de demo et envoie un email aux super admins.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'business_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:50',
            'profile' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        $validated['is_read'] = false;
        $demoRequest = DemoRequest::create($validated);

        $superAdmins = User::where('role', 'super_admin')
            ->whereNotNull('email')
            ->get(['email']);

        foreach ($superAdmins as $superAdmin) {
            Mail::to($superAdmin->email)->send(new DemoRequestReceived($demoRequest));
        }

        return response()->json([
            'message' => 'Demande envoyee avec succes',
            'id' => $demoRequest->id,
        ], 201);
    }

    /**
     * Liste des demandes de demo (super admin uniquement).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Acces reserve au super administrateur'], 403);
        }

        $items = DemoRequest::orderBy('created_at', 'desc')->get();
        return response()->json($items);
    }

    /**
     * Marque une demande comme lue (super admin uniquement).
     */
    public function markAsRead(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Acces reserve au super administrateur'], 403);
        }

        $item = DemoRequest::findOrFail($id);
        if (!$item->is_read) {
            $item->is_read = true;
            $item->save();
        }

        return response()->json([
            'message' => 'Demande marquee comme lue',
            'item' => $item,
        ]);
    }
}

