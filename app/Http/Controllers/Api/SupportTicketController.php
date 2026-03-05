<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    /**
     * Créer un ticket de support (côté établissement).
     * Tout utilisateur lié à un store peut créer un ticket.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Vous devez appartenir à un établissement pour créer un ticket'], 403);
        }

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        $ticket = SupportTicket::create([
            'store_id' => $user->store_id,
            'user_id' => $user->id,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'status' => 'open',
        ]);

        return response()->json($ticket->load('store:id,name,establishment_code', 'user:id,name,email'), 201);
    }

    /**
     * Liste des tickets.
     * - super_admin : voit tous les tickets (avec filtres optionnels)
     * - autres utilisateurs : ne voient que les tickets de leur établissement.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = SupportTicket::with(['store:id,name,establishment_code', 'user:id,name,email']);

        if ($user->role !== 'super_admin') {
            if (!$user->store_id) {
                return response()->json([]);
            }
            $query->where('store_id', $user->store_id);
        } else {
            if ($request->filled('store_id')) {
                $query->where('store_id', $request->input('store_id'));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $tickets = $query->orderBy('created_at', 'desc')->get();

        return response()->json($tickets);
    }

    /**
     * Mettre à jour un ticket (super admin).
     */
    public function update(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:open,in_progress,resolved,closed',
            'admin_note' => 'nullable|string|max:5000',
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $ticket->fill($validated);
        $ticket->save();

        return response()->json($ticket->fresh()->load('store:id,name,establishment_code', 'user:id,name,email'));
    }

    /**
     * Nombre de tickets non lus par le super admin (super_admin_seen_at null).
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['count' => 0]);
        }
        $count = SupportTicket::whereNull('super_admin_seen_at')->count();
        return response()->json(['count' => $count]);
    }

    /**
     * Marquer tous les tickets comme lus par le super admin.
     */
    public function markSeen(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['message' => 'Accès réservé au super administrateur'], 403);
        }
        SupportTicket::whereNull('super_admin_seen_at')->update(['super_admin_seen_at' => now()]);
        return response()->json(['message' => 'ok']);
    }
}

