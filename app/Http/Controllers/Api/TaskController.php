<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskCreatedNotification;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = Task::with(['assignedTo', 'assignedBy', 'store']);

        // Filtrer par store_id
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        // Si admin/chef/directeur, voir toutes les tâches de l'établissement
        // Sinon, voir seulement ses propres tâches
        if (!$user->hasSharedPermission('tasks')) {
            $query->where('assigned_to', $user->id);
        }

        // Filtres optionnels
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Trier par tâche à traiter en premier : date d'échéance la plus proche, puis priorité (urgent > haute > moyenne > basse)
        $tasks = $query
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date', 'asc')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($tasks);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent créer des tâches
        if (!$user->hasSharedPermission('tasks')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'recurrence_days' => 'nullable|array',
            'recurrence_days.*' => 'integer|min:1|max:7',
            'recurrence_duration_months' => 'nullable|integer|min:1|max:24',
            'recurrence_start_date' => 'nullable|date',
        ]);

        // Vérifier que l'utilisateur assigné appartient au même établissement
        $assignedUser = User::findOrFail($validated['assigned_to']);
        if ($assignedUser->store_id !== $user->store_id) {
            return response()->json(['message' => 'L\'employé doit appartenir au même établissement'], 403);
        }

        $recurrenceDays = array_values(array_unique(array_map('intval', $validated['recurrence_days'] ?? [])));
        $recurrenceMonths = isset($validated['recurrence_duration_months']) ? (int) $validated['recurrence_duration_months'] : 0;
        $useRecurrence = count($recurrenceDays) > 0 && $recurrenceMonths >= 1;

        if ($useRecurrence) {
            $startDate = isset($validated['recurrence_start_date'])
                ? Carbon::parse($validated['recurrence_start_date'])->startOfDay()
                : (isset($validated['due_date']) ? Carbon::parse($validated['due_date'])->startOfDay() : Carbon::today()->startOfDay());
            $endDate = $startDate->copy()->addMonths($recurrenceMonths);
            $weekStart = $startDate->copy()->startOfWeek(Carbon::MONDAY);
            $occurrenceDates = [];

            while ($weekStart->lt($endDate)) {
                foreach ($recurrenceDays as $dayOfWeek) {
                    $candidate = $weekStart->copy()->addDays($dayOfWeek - 1);
                    if ($candidate->gte($startDate) && $candidate->lt($endDate)) {
                        $occurrenceDates[] = $candidate->format('Y-m-d');
                    }
                }
                $weekStart->addWeek();
            }

            $created = [];
            foreach ($occurrenceDates as $dueDateStr) {
                $task = Task::create([
                    'store_id' => $user->store_id,
                    'assigned_to' => $validated['assigned_to'],
                    'assigned_by' => $user->id,
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? null,
                    'priority' => $validated['priority'] ?? 'medium',
                    'due_date' => $dueDateStr,
                    'notes' => $validated['notes'] ?? null,
                    'status' => 'pending',
                ]);
                $task->load(['assignedTo', 'assignedBy', 'store']);
                $created[] = $task;
                try {
                    if ($assignedUser->email_verified_at) {
                        $assignedUser->notify(new TaskCreatedNotification($task, $user));
                    }
                } catch (\Exception $e) {
                    \Log::error('Erreur envoi notification tâche créée: ' . $e->getMessage());
                }
            }

            return response()->json([
                'task' => $created[0] ?? null,
                'created_count' => count($created),
            ], 201);
        }

        $task = Task::create([
            'store_id' => $user->store_id,
            'assigned_to' => $validated['assigned_to'],
            'assigned_by' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? 'medium',
            'due_date' => $validated['due_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        $task->load(['assignedTo', 'assignedBy', 'store']);

        try {
            if ($assignedUser->email_verified_at) {
                $assignedUser->notify(new TaskCreatedNotification($task, $user));
            }
        } catch (\Exception $e) {
            \Log::error('Erreur envoi notification tâche créée: ' . $e->getMessage());
        }

        return response()->json($task, 201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $task = Task::where('id', $id);
        if ($user->store_id) {
            $task->where('store_id', $user->store_id);
        }
        $task = $task->firstOrFail();

        // Ne pas modifier une tâche déjà terminée
        if ($task->status === 'completed') {
            return response()->json(['message' => 'Une tâche terminée ne peut pas être modifiée.'], 422);
        }

        // L'employé assigné peut mettre à jour le statut et les notes
        // Admin/chef/directeur peuvent tout modifier
        $canUpdateAll = $user->hasSharedPermission('tasks') || $task->assigned_by === $user->id;
        $canUpdateStatus = $task->assigned_to === $user->id || $canUpdateAll;
        // Seuls le créateur et les admins/chefs/directeurs peuvent annuler une tâche
        $canCancel = $canUpdateAll;

        $validated = $request->validate([
            'title' => $canUpdateAll ? 'sometimes|string|max:255' : 'prohibited',
            'description' => $canUpdateAll ? 'nullable|string' : 'prohibited',
            'priority' => $canUpdateAll ? 'nullable|in:low,medium,high,urgent' : 'prohibited',
            'due_date' => $canUpdateAll ? 'nullable|date' : 'prohibited',
            'status' => $canUpdateStatus ? 'sometimes|in:pending,in_progress,completed,cancelled' : 'prohibited',
            'notes' => 'nullable|string',
        ]);

        // Vérifier si l'utilisateur essaie d'annuler la tâche
        if (isset($validated['status']) && $validated['status'] === 'cancelled' && !$canCancel) {
            return response()->json(['message' => 'Vous n\'avez pas la permission d\'annuler cette tâche'], 403);
        }

        // L'employé assigné ne peut pas démarrer ni terminer une tâche avant la date d'échéance
        $isAssigneeOnly = $task->assigned_to === $user->id && !$canUpdateAll;
        if ($isAssigneeOnly && isset($validated['status']) && in_array($validated['status'], ['in_progress', 'completed'], true)) {
            $dueDate = $task->due_date ? Carbon::parse($task->due_date)->startOfDay() : null;
            if ($dueDate && now()->startOfDay()->lt($dueDate)) {
                $formatted = $dueDate->locale('fr_FR')->isoFormat('DD/MM/YYYY');
                return response()->json([
                    'message' => "Cette tâche ne peut pas être effectuée avant la date prévue. Date d'échéance : {$formatted}.",
                ], 422);
            }
        }

        // Si le statut passe à "in_progress", enregistrer la date de début
        if (isset($validated['status']) && $validated['status'] === 'in_progress' && $task->status !== 'in_progress') {
            $validated['started_at'] = now();
        } elseif (isset($validated['status']) && $validated['status'] !== 'in_progress' && $validated['status'] !== 'completed') {
            // Si on revient à un autre statut (pending, cancelled), réinitialiser started_at
            $validated['started_at'] = null;
        }

        // Si le statut passe à "completed", enregistrer la date de fin
        if (isset($validated['status']) && $validated['status'] === 'completed' && $task->status !== 'completed') {
            $validated['completed_at'] = now();
            // S'assurer que started_at est défini si ce n'est pas déjà le cas
            if (!$task->started_at) {
                $validated['started_at'] = now();
            }
        } elseif (isset($validated['status']) && $validated['status'] !== 'completed') {
            // Si on revient à un autre statut, réinitialiser completed_at
            $validated['completed_at'] = null;
        }

        $task->update($validated);

        return response()->json($task->load(['assignedTo', 'assignedBy', 'store']));
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent supprimer
        if (!$user->hasSharedPermission('tasks')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $task = Task::where('id', $id);
        if ($user->store_id) {
            $task->where('store_id', $user->store_id);
        }
        $task = $task->firstOrFail();

        $task->delete();

        return response()->json(['message' => 'Tâche supprimée avec succès']);
    }

    public function myTasks(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $tasks = Task::where('assigned_to', $user->id)
            ->with(['assignedBy', 'store'])
            ->orderBy('due_date', 'asc')
            ->orderBy('priority', 'desc')
            ->get();

        return response()->json($tasks);
    }

    /**
     * Nombre de tâches assignées à l'utilisateur (pending + in_progress) pour le badge.
     */
    public function myTasksCount(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $count = Task::where('assigned_to', $user->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        return response()->json(['count' => $count]);
    }
}
