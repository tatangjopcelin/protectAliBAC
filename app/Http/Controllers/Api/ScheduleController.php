<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ScheduleController extends Controller
{
    /**
     * Display a listing of schedules.
     * Tous les utilisateurs peuvent voir leur propre planning
     * Admin/chef/directeur peuvent voir tous les plannings
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = Schedule::with(['user', 'creator']);

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que son propre planning
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            $query->where('user_id', $user->id);
        }

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
            // Gérer plusieurs statuts séparés par des virgules
            $statuses = explode(',', $request->status);
            if (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            } else {
                $query->where('status', $request->status);
            }
        }

        // Tri par date
        $query->orderBy('date', 'asc')->orderBy('start_time', 'asc');

        $schedules = $query->get();

        return response()->json($schedules);
    }

    /**
     * Store a newly created schedule.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent créer des plannings
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'break_duration' => 'nullable|date_format:H:i',
            'start_break' => 'nullable|date_format:H:i',
            'end_break' => 'nullable|date_format:H:i|after:start_break',
            'status' => 'nullable|in:planned,confirmed,cancelled',
            'notes' => 'nullable|string',
        ]);

        // Calculer break_duration si start_break et end_break sont fournis
        $breakDuration = null;
        if (!empty($validated['start_break']) && !empty($validated['end_break'])) {
            // Parser les heures au format H:i (ex: "12:00")
            $startBreak = Carbon::createFromFormat('H:i', $validated['start_break']);
            $endBreak = Carbon::createFromFormat('H:i', $validated['end_break']);
            
            // Si end_break est avant start_break, on suppose que c'est le lendemain
            if ($endBreak->lt($startBreak)) {
                $endBreak->addDay();
            }
            
            $breakDurationMinutes = $startBreak->diffInMinutes($endBreak);
            // Convertir en format H:i
            $hours = floor($breakDurationMinutes / 60);
            $minutes = $breakDurationMinutes % 60;
            $breakDuration = sprintf('%02d:%02d', $hours, $minutes);
        } elseif (!empty($validated['break_duration'])) {
            $breakDuration = $validated['break_duration'];
        }

        $schedule = Schedule::create([
            'user_id' => $validated['user_id'],
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'break_duration' => $breakDuration,
            'start_break' => $validated['start_break'] ?? null,
            'end_break' => $validated['end_break'] ?? null,
            'status' => $validated['status'] ?? 'planned',
            'notes' => $validated['notes'] ?? null,
            'created_by' => $user->id,
        ]);
        
        \Log::info('Planning créé:', [
            'id' => $schedule->id,
            'start_break' => $schedule->start_break,
            'end_break' => $schedule->end_break,
            'break_duration' => $schedule->break_duration
        ]);

        return response()->json($schedule->load(['user', 'creator']), 201);
    }

    /**
     * Display the specified schedule.
     * Tous les utilisateurs peuvent voir leur propre planning
     * Admin/chef/directeur peuvent voir tous les plannings
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $schedule = Schedule::with(['user', 'creator', 'timeEntry'])->findOrFail($id);

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que son propre planning
        if (!in_array($user->role, ['admin', 'chef', 'director']) && $schedule->user_id !== $user->id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($schedule);
    }

    /**
     * Update the specified schedule.
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent modifier des plannings
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $schedule = Schedule::findOrFail($id);

        $validated = $request->validate([
            'user_id' => 'sometimes|exists:users,id',
            'date' => 'sometimes|date',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'break_duration' => 'nullable|date_format:H:i',
            'start_break' => 'nullable|date_format:H:i',
            'end_break' => 'nullable|date_format:H:i|after:start_break',
            'status' => 'sometimes|in:planned,confirmed,cancelled',
            'notes' => 'nullable|string',
        ]);

        // Calculer break_duration si start_break et end_break sont fournis
        if (!empty($validated['start_break']) && !empty($validated['end_break'])) {
            $startBreak = Carbon::parse($validated['start_break']);
            $endBreak = Carbon::parse($validated['end_break']);
            $breakMinutes = $startBreak->diffInMinutes($endBreak);
            // Convertir en format H:i
            $hours = floor($breakMinutes / 60);
            $minutes = $breakMinutes % 60;
            $validated['break_duration'] = sprintf('%02d:%02d', $hours, $minutes);
        }

        $updateData = $validated;
        if (isset($validated['start_break'])) {
            $updateData['start_break'] = $validated['start_break'];
        }
        if (isset($validated['end_break'])) {
            $updateData['end_break'] = $validated['end_break'];
        }
        $schedule->update($updateData);

        return response()->json($schedule->load(['user', 'creator']));
    }

    /**
     * Remove the specified schedule.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent supprimer des plannings
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $schedule = Schedule::findOrFail($id);
        $schedule->delete();

        return response()->json(['message' => 'Planning supprimé'], 200);
    }

    /**
     * Get weekly schedule for a user or all users
     * Tous les utilisateurs peuvent voir leur propre planning
     * Admin/chef/directeur peuvent voir tous les plannings
     */
    public function getWeeklySchedule(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $weekStart = $request->has('week_start') 
            ? Carbon::parse($request->week_start)->startOfWeek()
            : Carbon::now()->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        $query = Schedule::with(['user', 'creator'])
            ->whereBetween('date', [$weekStart, $weekEnd]);

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que son propre planning
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            $query->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $schedules = $query->orderBy('date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'schedules' => $schedules
        ]);
    }

    /**
     * Get monthly schedule for a user or all users
     * Tous les utilisateurs peuvent voir leur propre planning
     * Admin/chef/directeur peuvent voir tous les plannings
     */
    public function getMonthlySchedule(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $month = $request->has('month') 
            ? Carbon::parse($request->month)->startOfMonth()
            : Carbon::now()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $query = Schedule::with(['user', 'creator'])
            ->whereBetween('date', [$month, $monthEnd]);

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que son propre planning
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            $query->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $schedules = $query->orderBy('date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'month' => $month->format('Y-m'),
            'schedules' => $schedules
        ]);
    }

    /**
     * Valider un planning "request" (demande)
     * Seuls admin, chef et directeur peuvent valider
     */
    public function validateRequest(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent valider
        if (!in_array($user->role, ['admin', 'chef', 'director'])) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $schedule = Schedule::findOrFail($id);

        // Vérifier que c'est bien un planning "request"
        if ($schedule->status !== 'request') {
            return response()->json([
                'message' => 'Ce planning n\'est pas une demande en attente de validation'
            ], 400);
        }

        // Changer le statut en "confirmed"
        $schedule->status = 'confirmed';
        $schedule->save();

        return response()->json([
            'message' => 'Planning validé avec succès. Les heures seront maintenant comptabilisées.',
            'schedule' => $schedule->load(['user', 'creator'])
        ]);
    }
}
