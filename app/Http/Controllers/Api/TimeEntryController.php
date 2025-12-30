<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TimeEntry;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TimeEntryController extends Controller
{
    /**
     * Display a listing of time entries.
     */
    public function index(Request $request)
    {
        $query = TimeEntry::with(['user', 'schedule', 'breaks']);

        // Filtres
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Tri par date décroissante
        $query->orderBy('date', 'desc')->orderBy('clock_in', 'desc');

        $timeEntries = $query->get();

        return response()->json($timeEntries);
    }

    /**
     * Pointage d'arrivée (clock in)
     * Seuls admin, chef et directeur peuvent pointer
     */
    public function clockIn(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent pointer
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé. Seuls les administrateurs, chefs et directeurs peuvent pointer.'], 403);
        }

        $validated = $request->validate([
            'location' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $today = Carbon::today();
        
        // Vérifier si un pointage existe déjà pour aujourd'hui
        $existingEntry = TimeEntry::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        if ($existingEntry && $existingEntry->clock_in) {
            return response()->json([
                'message' => 'Vous avez déjà pointé votre arrivée aujourd\'hui',
                'time_entry' => $existingEntry->load(['user', 'schedule'])
            ], 400);
        }

        // Chercher le planning du jour
        $schedule = Schedule::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->where('status', '!=', 'cancelled')
            ->first();

        $timeEntry = TimeEntry::updateOrCreate(
            [
                'user_id' => $user->id,
                'date' => $today,
            ],
            [
                'schedule_id' => $schedule?->id,
                'clock_in' => now(),
                'location' => $validated['location'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'present',
            ]
        );

        // Vérifier si l'utilisateur est en retard
        if ($schedule) {
            $scheduledStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_time);
            $actualStart = Carbon::parse($timeEntry->clock_in);
            
            if ($actualStart->gt($scheduledStart->addMinutes(15))) {
                $timeEntry->status = 'late';
                $timeEntry->save();
            }
        }

        return response()->json([
            'message' => 'Pointage d\'arrivée enregistré',
            'time_entry' => $timeEntry->load(['user', 'schedule'])
        ], 201);
    }

    /**
     * Pointage de départ (clock out)
     * Seuls admin, chef et directeur peuvent pointer
     */
    public function clockOut(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent pointer
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé. Seuls les administrateurs, chefs et directeurs peuvent pointer.'], 403);
        }

        $validated = $request->validate([
            'break_duration' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $today = Carbon::today();
        
        // Trouver le pointage du jour
        $timeEntry = TimeEntry::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        if (!$timeEntry) {
            return response()->json([
                'message' => 'Aucun pointage d\'arrivée trouvé pour aujourd\'hui'
            ], 400);
        }

        if ($timeEntry->clock_out) {
            return response()->json([
                'message' => 'Vous avez déjà pointé votre départ aujourd\'hui',
                'time_entry' => $timeEntry->load(['user', 'schedule'])
            ], 400);
        }

        $timeEntry->clock_out = now();
        $timeEntry->break_duration = $validated['break_duration'] ?? $timeEntry->break_duration;
        
        if ($timeEntry->notes) {
            $timeEntry->notes .= "\n" . ($validated['notes'] ?? '');
        } else {
            $timeEntry->notes = $validated['notes'] ?? null;
        }

        // Calculer les heures travaillées
        $timeEntry->hours_worked = $timeEntry->calculateHoursWorked();
        $timeEntry->save();

        return response()->json([
            'message' => 'Pointage de départ enregistré',
            'time_entry' => $timeEntry->load(['user', 'schedule', 'breaks']),
            'hours_worked' => $timeEntry->hours_worked
        ]);
    }

    /**
     * Get today's time entry for the authenticated user
     */
    public function getTodayEntry(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $today = Carbon::today();
        $timeEntry = TimeEntry::with(['user', 'schedule', 'breaks'])
            ->where('user_id', $user->id)
            ->whereDate('date', $today)
            ->first();

        return response()->json($timeEntry ?? null);
    }

    /**
     * Get user's time entries
     */
    public function getUserEntries(Request $request, string $userId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Un utilisateur peut voir ses propres pointages, ou admin/chef/directeur peuvent voir tous
        if ($user->id != $userId && !in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $query = TimeEntry::with(['user', 'schedule', 'breaks'])
            ->where('user_id', $userId);

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->start_date, $request->end_date]);
        } else {
            // Par défaut, afficher les 30 derniers jours
            $query->where('date', '>=', Carbon::now()->subDays(30));
        }

        $timeEntries = $query->orderBy('date', 'desc')->get();

        return response()->json($timeEntries);
    }

    /**
     * Update a time entry (admin only)
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent modifier des pointages
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $timeEntry = TimeEntry::findOrFail($id);

        $validated = $request->validate([
            'clock_in' => 'sometimes|date',
            'clock_out' => 'sometimes|date|after:clock_in',
            'hours_worked' => 'sometimes|numeric|min:0',
            'break_duration' => 'nullable|numeric|min:0',
            'status' => 'sometimes|in:present,absent,late,early_leave',
            'notes' => 'nullable|string',
        ]);

        $timeEntry->update($validated);

        // Recalculer les heures travaillées si nécessaire
        if ($timeEntry->clock_in && $timeEntry->clock_out) {
            $timeEntry->hours_worked = $timeEntry->calculateHoursWorked();
            $timeEntry->save();
        }

        return response()->json($timeEntry->load(['user', 'schedule']));
    }

    /**
     * Get statistics for time entries
     * Tous les utilisateurs peuvent voir leurs propres statistiques
     * Admin/chef/directeur peuvent voir les statistiques de tous
     */
    public function getStatistics(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que ses propres statistiques
        $userId = $request->has('user_id') && in_array($user->role, ['admin', 'chef', 'director'])
            ? $request->user_id
            : $user->id;

        $startDate = $request->has('start_date') 
            ? Carbon::parse($request->start_date)
            : Carbon::now()->startOfMonth();
        $endDate = $request->has('end_date')
            ? Carbon::parse($request->end_date)
            : Carbon::now()->endOfMonth();

        $timeEntries = TimeEntry::where('user_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $totalHours = $timeEntries->sum('hours_worked');
        $totalDays = $timeEntries->where('status', 'present')->count();
        $lateCount = $timeEntries->where('status', 'late')->count();
        $absentCount = $timeEntries->where('status', 'absent')->count();

        return response()->json([
            'user_id' => $userId,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'statistics' => [
                'total_hours' => round($totalHours, 2),
                'total_days' => $totalDays,
                'late_count' => $lateCount,
                'absent_count' => $absentCount,
                'average_hours_per_day' => $totalDays > 0 ? round($totalHours / $totalDays, 2) : 0,
            ],
            'time_entries' => $timeEntries->load(['user', 'schedule'])
        ]);
    }
}
