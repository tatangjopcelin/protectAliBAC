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

        $tasks = $query->orderBy('created_at', 'desc')->get();

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
        ]);

        // Vérifier que l'utilisateur assigné appartient au même établissement
        $assignedUser = User::findOrFail($validated['assigned_to']);
        if ($assignedUser->store_id !== $user->store_id) {
            return response()->json(['message' => 'L\'employé doit appartenir au même établissement'], 403);
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

        // Charger les relations pour la notification
        $task->load(['assignedTo', 'assignedBy', 'store']);

        // Envoyer une notification par email à l'employé assigné
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
