<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntryCorrection;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TimeEntryCorrectionController extends Controller
{
    /**
     * Display a listing of correction requests.
     * Les utilisateurs voient leurs propres demandes
     * Les admins voient toutes les demandes en attente
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = TimeEntryCorrection::with(['timeEntry.user', 'timeEntry.schedule', 'user', 'reviewer']);

        // Si admin/chef/directeur, voir toutes les demandes en attente par défaut
        if ($user->hasSharedPermission('time_entry')) {
            if ($request->has('status')) {
                $query->where('status', $request->status);
            } else {
                // Par défaut, montrer les demandes en attente
                $query->where('status', 'pending');
            }
        } else {
            // Sinon, voir seulement ses propres demandes
            $query->where('user_id', $user->id);
        }

        $corrections = $query->orderBy('created_at', 'desc')->get();

        return response()->json($corrections);
    }

    /**
     * Store a newly created correction request.
     * Tous les utilisateurs peuvent créer une demande pour leur propre pointage
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $validated = $request->validate([
            'time_entry_id' => 'required|exists:time_entries,id',
            'requested_clock_in' => 'required|date',
            'requested_clock_out' => 'nullable|date|after:requested_clock_in',
            'reason' => 'nullable|string|max:1000',
        ]);

        // Vérifier que le pointage appartient à l'utilisateur
        $timeEntry = TimeEntry::findOrFail($validated['time_entry_id']);
        if ($timeEntry->user_id !== $user->id) {
            return response()->json(['message' => 'Vous ne pouvez demander une correction que pour vos propres pointages'], 403);
        }

        // Vérifier qu'il n'y a pas déjà une demande en attente pour ce pointage
        $existingRequest = TimeEntryCorrection::where('time_entry_id', $validated['time_entry_id'])
            ->where('status', 'pending')
            ->first();

        if ($existingRequest) {
            return response()->json(['message' => 'Une demande de correction est déjà en attente pour ce pointage'], 400);
        }

        // S'assurer que les dates sont interprétées comme UTC lors du stockage
        // Si la date se termine par 'Z', Carbon la parse correctement en UTC
        // Sinon, on force l'interprétation UTC
        $requestedClockIn = $validated['requested_clock_in'];
        $requestedClockOut = $validated['requested_clock_out'] ?? null;
        
        // Carbon::parse() avec 'Z' à la fin interprète correctement comme UTC
        // On s'assure que la date est au format ISO avec 'Z'
        if (!str_ends_with($requestedClockIn, 'Z') && !str_contains($requestedClockIn, '+') && !preg_match('/[+-]\d{2}:\d{2}$/', $requestedClockIn)) {
            $requestedClockIn = $requestedClockIn . 'Z';
        }
        if ($requestedClockOut && !str_ends_with($requestedClockOut, 'Z') && !str_contains($requestedClockOut, '+') && !preg_match('/[+-]\d{2}:\d{2}$/', $requestedClockOut)) {
            $requestedClockOut = $requestedClockOut . 'Z';
        }
        
        $correction = TimeEntryCorrection::create([
            'time_entry_id' => $validated['time_entry_id'],
            'user_id' => $user->id,
            'requested_clock_in' => $requestedClockIn,
            'requested_clock_out' => $requestedClockOut,
            'reason' => $validated['reason'] ?? null,
            'status' => 'pending',
        ]);

        return response()->json($correction->load(['timeEntry.user', 'timeEntry.schedule', 'user']), 201);
    }

    /**
     * Display the specified correction request.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $correction = TimeEntryCorrection::with(['timeEntry.user', 'timeEntry.schedule', 'user', 'reviewer'])->findOrFail($id);

        // Vérifier les permissions
        if (!$user->hasSharedPermission('time_entry') && $correction->user_id !== $user->id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($correction);
    }

    /**
     * Approve or reject a correction request (admin only)
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent approuver/rejeter
        if (!$user->hasSharedPermission('time_entry')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $correction = TimeEntryCorrection::findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'review_notes' => 'nullable|string|max:1000',
        ]);

        // Si la demande est approuvée, mettre à jour le pointage
        if ($validated['status'] === 'approved') {
            $timeEntry = $correction->timeEntry;
            
            $timeEntry->update([
                'clock_in' => $correction->requested_clock_in,
                'clock_out' => $correction->requested_clock_out ?? $timeEntry->clock_out,
            ]);

            // Recalculer les heures travaillées
            if ($timeEntry->clock_in && $timeEntry->clock_out) {
                $timeEntry->hours_worked = $timeEntry->calculateHoursWorked();
                $timeEntry->save();
            }
        }

        // Mettre à jour la demande
        $correction->update([
            'status' => $validated['status'],
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $validated['review_notes'] ?? null,
        ]);

        return response()->json($correction->load(['timeEntry.user', 'timeEntry.schedule', 'user', 'reviewer']));
    }

    /**
     * Remove the specified correction request (only if pending)
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $correction = TimeEntryCorrection::findOrFail($id);

        // Vérifier que l'utilisateur est le propriétaire ou un admin
        if (!$user->hasSharedPermission('time_entry') && $correction->user_id !== $user->id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        // Seulement supprimer si la demande est en attente
        if ($correction->status !== 'pending') {
            return response()->json(['message' => 'Impossible de supprimer une demande déjà traitée'], 400);
        }

        $correction->delete();

        return response()->json(['message' => 'Demande de correction supprimée'], 200);
    }
}
