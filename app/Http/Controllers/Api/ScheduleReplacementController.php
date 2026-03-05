<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\ScheduleReplacementRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleReplacementController extends Controller
{
    /**
     * Créer une demande de remplacement pour un planning (employé)
     */
    public function store(Request $request, int $scheduleId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $schedule = Schedule::with('user')->findOrFail($scheduleId);

        if ($schedule->user_id !== $user->id) {
            return response()->json(['message' => 'Vous ne pouvez demander un remplacement que pour votre propre planning'], 403);
        }

        if ($user->store_id && $schedule->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if (in_array($schedule->status, ['cancelled'])) {
            return response()->json(['message' => 'Ce planning est déjà annulé'], 400);
        }

        $existing = ScheduleReplacementRequest::where('schedule_id', $scheduleId)
            ->where('status', 'pending')
            ->first();
        if ($existing) {
            return response()->json(['message' => 'Une demande de remplacement est déjà en attente pour ce créneau'], 400);
        }

        $req = ScheduleReplacementRequest::create([
            'schedule_id' => $scheduleId,
            'requested_by' => $user->id,
            'status' => 'pending',
        ]);

        $req->load(['schedule.user', 'requestedByUser']);
        return response()->json($req, 201);
    }

    /**
     * Liste des demandes (admin/directeur) — avec filtre optionnel status
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        if (!in_array($user->role, ['admin', 'director']) && !$user->hasSharedPermission('planning')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $query = ScheduleReplacementRequest::with([
            'schedule.user',
            'schedule.store',
            'requestedByUser',
            'replacementUser',
            'respondedByUser',
        ]);

        if ($user->store_id) {
            $query->whereHas('schedule', function ($q) use ($user) {
                $q->where('store_id', $user->store_id);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json($requests);
    }

    /**
     * Répondre à une demande : accepter (avec ou sans remplaçant) ou rejeter
     */
    public function respond(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        if (!in_array($user->role, ['admin', 'director']) && !$user->hasSharedPermission('planning')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'action' => 'required|in:accept,reject',
            'replacement_user_id' => 'nullable|exists:users,id',
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $replacementRequest = ScheduleReplacementRequest::with('schedule')->findOrFail($id);
        if ($replacementRequest->status !== 'pending') {
            return response()->json(['message' => 'Cette demande a déjà été traitée'], 400);
        }

        $schedule = $replacementRequest->schedule;
        if ($user->store_id && $schedule->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if ($validated['action'] === 'reject') {
            $replacementRequest->update([
                'status' => 'rejected',
                'responded_by' => $user->id,
                'responded_at' => now(),
                'rejection_reason' => $validated['rejection_reason'] ?? null,
            ]);
            $replacementRequest->load(['schedule.user', 'requestedByUser', 'respondedByUser']);
            return response()->json([
                'message' => 'Demande refusée',
                'replacement_request' => $replacementRequest,
            ]);
        }

        // Accept
        $replacementUserId = $validated['replacement_user_id'] ?? null;

        if ($replacementUserId !== null) {
            $replacementUser = User::findOrFail($replacementUserId);
            if ($replacementUser->store_id !== $schedule->store_id) {
                return response()->json(['message' => 'Le remplaçant doit appartenir au même établissement'], 422);
            }
            if ($replacementUser->id === $replacementRequest->requested_by) {
                return response()->json(['message' => 'Le remplaçant ne peut pas être la même personne'], 422);
            }
        }

        DB::transaction(function () use ($replacementRequest, $schedule, $user, $replacementUserId) {
            $replacementRequest->update([
                'status' => 'accepted',
                'replacement_user_id' => $replacementUserId,
                'responded_by' => $user->id,
                'responded_at' => now(),
            ]);

            if ($replacementUserId !== null) {
                Schedule::create([
                    'user_id' => $replacementUserId,
                    'store_id' => $schedule->store_id,
                    'date' => $schedule->date,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'break_duration' => $schedule->break_duration,
                    'start_break' => $schedule->start_break,
                    'end_break' => $schedule->end_break,
                    'status' => $schedule->status === 'request' ? 'request' : 'planned',
                    'notes' => 'Remplacement pour ' . ($schedule->user->name ?? '') . '. ' . ($schedule->notes ?? ''),
                    'created_by' => $user->id,
                ]);
            }

            $schedule->update(['status' => 'cancelled']);
        });

        $replacementRequest->load(['schedule.user', 'requestedByUser', 'replacementUser', 'respondedByUser']);
        return response()->json([
            'message' => $replacementUserId ? 'Demande acceptée, le planning a été assigné au remplaçant.' : 'Demande acceptée (sans remplaçant).',
            'replacement_request' => $replacementRequest,
        ]);
    }

    /**
     * Récupérer les demandes par schedule_ids (pour afficher sur le planning employé)
     */
    public function getByScheduleIds(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $scheduleIds = $request->input('schedule_ids', []);
        if (is_string($scheduleIds)) {
            $scheduleIds = array_filter(array_map('intval', explode(',', $scheduleIds)));
        }
        if (!is_array($scheduleIds) || empty($scheduleIds)) {
            return response()->json([]);
        }

        $requests = ScheduleReplacementRequest::with(['requestedByUser', 'replacementUser'])
            ->whereIn('schedule_id', $scheduleIds)
            ->orderBy('created_at', 'desc')
            ->get();

        $bySchedule = [];
        foreach ($requests as $req) {
            $sid = $req->schedule_id;
            if (!isset($bySchedule[$sid]) || $bySchedule[$sid]->status === 'pending') {
                $bySchedule[$sid] = $req;
            }
        }

        return response()->json(array_values($bySchedule));
    }
}
