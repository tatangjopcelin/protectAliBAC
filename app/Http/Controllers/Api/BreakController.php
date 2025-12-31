<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkBreak;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BreakController extends Controller
{
    /**
     * Démarrer une pause
     */
    public function startBreak(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        // Si un user_id est fourni et que l'utilisateur est admin/chef/director, utiliser cet user_id
        // Sinon, utiliser l'utilisateur authentifié
        $targetUserId = $validated['user_id'] ?? $user->id;
        
        // Vérifier les permissions : seuls admin/chef/director peuvent démarrer une pause pour un autre utilisateur
        if ($targetUserId !== $user->id && !in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        // Trouver le time entry actif pour cet utilisateur
        $timeEntry = TimeEntry::where('user_id', $targetUserId)
            ->whereDate('date', Carbon::today())
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->orderBy('clock_in', 'desc') // Prendre le plus récent
            ->first();

        if (!$timeEntry) {
            return response()->json(['message' => 'Aucun pointage actif trouvé'], 404);
        }

        // Vérifier s'il y a déjà une pause en cours
        $activeBreak = WorkBreak::where('time_entry_id', $timeEntry->id)
            ->whereNotNull('start_break')
            ->whereNull('end_break')
            ->first();

        if ($activeBreak) {
            return response()->json(['message' => 'Une pause est déjà en cours'], 400);
        }

        $break = WorkBreak::create([
            'time_entry_id' => $timeEntry->id,
            'user_id' => $targetUserId,
            'start_break' => Carbon::now(),
        ]);

        return response()->json($break, 201);
    }

    /**
     * Terminer une pause
     */
    public function endBreak(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        // Si un user_id est fourni et que l'utilisateur est admin/chef/director, utiliser cet user_id
        // Sinon, utiliser l'utilisateur authentifié
        $targetUserId = $validated['user_id'] ?? $user->id;
        
        // Vérifier les permissions : seuls admin/chef/director peuvent terminer une pause pour un autre utilisateur
        if ($targetUserId !== $user->id && !in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        // Trouver la pause active pour cet utilisateur
        $break = WorkBreak::where('user_id', $targetUserId)
            ->whereNotNull('start_break')
            ->whereNull('end_break')
            ->latest()
            ->first();

        if (!$break) {
            return response()->json(['message' => 'Aucune pause active trouvée'], 404);
        }

        $break->end_break = Carbon::now();
        $break->duration_minutes = $break->calculateDuration();
        $break->save();

        // Mettre à jour le time entry avec la durée totale de pause (en minutes)
        $timeEntry = $break->timeEntry;
        $totalBreakMinutes = WorkBreak::where('time_entry_id', $timeEntry->id)
            ->whereNotNull('end_break')
            ->sum('duration_minutes');
        
        $timeEntry->break_duration = $totalBreakMinutes; // Stocker en minutes
        $timeEntry->hours_worked = $timeEntry->calculateHoursWorked();
        $timeEntry->save();

        return response()->json($break->load('timeEntry'));
    }

    /**
     * Obtenir les pauses d'un time entry
     */
    public function getBreaks(Request $request, $timeEntryId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $timeEntry = TimeEntry::findOrFail($timeEntryId);

        // Vérifier les permissions
        if ($timeEntry->user_id !== $user->id && !in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $breaks = WorkBreak::where('time_entry_id', $timeEntryId)
            ->orderBy('start_break', 'asc')
            ->get();

        return response()->json($breaks);
    }
}
