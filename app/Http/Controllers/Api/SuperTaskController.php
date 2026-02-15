<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuperTask;
use App\Models\User;
use App\Notifications\SuperTaskAssignedNotification;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SuperTaskController extends Controller
{
    /**
     * Formate week_start_date au format Y-m-d pour éviter les problèmes de timezone
     */
    private function formatSuperTask($superTask)
    {
        if ($superTask->week_start_date) {
            $superTask->week_start_date = Carbon::parse($superTask->week_start_date)->format('Y-m-d');
        }
        return $superTask;
    }

    /**
     * Formate une collection de super tâches
     */
    private function formatSuperTasks($superTasks)
    {
        return $superTasks->map(function ($superTask) {
            return $this->formatSuperTask($superTask);
        });
    }

    /**
     * Récupère toutes les super tâches de l'établissement
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = SuperTask::with(['assignedTo', 'assignedBy', 'store']);

        // Filtrer par store_id
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        // Si admin/chef/directeur, voir toutes les super tâches
        // Sinon, voir seulement ses propres super tâches
        if (!$user->hasSharedPermission('tasks')) {
            $query->where('assigned_to', $user->id);
        }

        // Filtres optionnels
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('week_start_date')) {
            $query->where('week_start_date', $request->week_start_date);
        }

        $superTasks = $query->orderBy('week_start_date', 'desc')
            ->orderBy('type', 'asc')
            ->get();

        // Formater week_start_date au format Y-m-d pour éviter les problèmes de timezone
        $superTasks = $this->formatSuperTasks($superTasks);

        return response()->json($superTasks);
    }

    /**
     * Récupère les super tâches de l'utilisateur connecté
     */
    public function mySuperTasks(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $superTasks = SuperTask::where('assigned_to', $user->id)
            ->with(['assignedBy', 'store'])
            ->where('status', '!=', 'completed')
            ->orderBy('week_start_date', 'desc')
            ->get();

        // Formater week_start_date au format Y-m-d
        $superTasks = $this->formatSuperTasks($superTasks);

        return response()->json($superTasks);
    }

    /**
     * Vérifie si l'utilisateur a des super tâches en attente
     */
    public function hasPendingSuperTasks(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['has_pending' => false]);
        }

        $count = SuperTask::where('assigned_to', $user->id)
            ->where('status', '!=', 'completed')
            ->where('week_start_date', '<=', Carbon::now()->endOfWeek())
            ->count();

        return response()->json(['has_pending' => $count > 0, 'count' => $count]);
    }

    /**
     * Crée une nouvelle super tâche
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin, chef et directeur peuvent créer des super tâches
        if (!$user->hasSharedPermission('tasks')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:friteuse,chambre_froide',
            'assigned_to' => 'required|exists:users,id',
            'week_start_date' => 'required|date',
        ]);

        // Normaliser la date au format Y-m-d
        $validated['week_start_date'] = Carbon::parse($validated['week_start_date'])->format('Y-m-d');

        // Vérifier que l'utilisateur assigné appartient au même établissement
        $assignedUser = User::findOrFail($validated['assigned_to']);
        if ($assignedUser->store_id !== $user->store_id) {
            return response()->json(['message' => 'L\'employé doit appartenir au même établissement'], 403);
        }

        // Calculer le lundi et le dimanche de la semaine en cours
        $today = Carbon::now();
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $currentWeekEnd = $today->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d');
        $selectedWeekStart = $validated['week_start_date'];

        // Vérifier que la date sélectionnée est dans la semaine en cours (lundi à dimanche)
        if ($selectedWeekStart < $currentWeekStart || $selectedWeekStart > $currentWeekEnd) {
            return response()->json([
                'message' => 'Vous ne pouvez créer une super tâche que pour la semaine en cours (du lundi au dimanche)'
            ], 400);
        }

        // Vérifier qu'il n'y a pas déjà une super tâche de ce type pour cette semaine
        $existing = SuperTask::where('store_id', $user->store_id)
            ->where('type', $validated['type'])
            ->where('week_start_date', $validated['week_start_date'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Une super tâche de ce type existe déjà pour cette semaine'], 409);
        }

        // Compter les super tâches existantes pour la semaine en cours (tous types confondus)
        $existingTasksCount = SuperTask::where('store_id', $user->store_id)
            ->where('week_start_date', $currentWeekStart)
            ->count();

        // Pour créer une deuxième super tâche (type différent) pour la semaine en cours,
        // vérifier qu'au moins une super tâche (Friteuse ou Chambre froide) existe déjà
        if ($existingTasksCount === 0) {
            // C'est la première super tâche de la semaine, on peut la créer sans condition
        } else {
            // Au moins une super tâche existe déjà, on peut créer une deuxième (type différent)
            // La vérification ci-dessus empêche déjà de créer deux super tâches du même type
        }

        $superTask = SuperTask::create([
            'store_id' => $user->store_id,
            'type' => $validated['type'],
            'assigned_to' => $validated['assigned_to'],
            'assigned_by' => $user->id,
            'week_start_date' => $validated['week_start_date'],
            'status' => 'pending',
        ]);

        // Charger les relations
        $superTask->load(['assignedTo', 'assignedBy', 'store']);

        // Envoyer une notification par email à l'employé assigné
        try {
            if ($assignedUser->email_verified_at) {
                $assignedUser->notify(new SuperTaskAssignedNotification($superTask, $user));
            }
        } catch (\Exception $e) {
            Log::error('Erreur envoi notification super tâche assignée: ' . $e->getMessage());
        }

        // Formater week_start_date
        $superTask = $this->formatSuperTask($superTask);

        return response()->json($superTask, 201);
    }

    /**
     * Met à jour une super tâche
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $superTask = SuperTask::where('id', $id);
        if ($user->store_id) {
            $superTask->where('store_id', $user->store_id);
        }
        $superTask = $superTask->firstOrFail();

        // L'employé assigné peut mettre à jour le statut et les champs spécifiques
        // Admin/chef/directeur peuvent tout modifier
        $canUpdateAll = $user->hasSharedPermission('tasks') || $superTask->assigned_by === $user->id;
        $canUpdateStatus = $superTask->assigned_to === $user->id || $canUpdateAll;

        $validated = $request->validate([
            'assigned_to' => $canUpdateAll ? 'sometimes|exists:users,id' : 'prohibited',
            'week_start_date' => $canUpdateAll ? 'sometimes|date' : 'prohibited',
            'status' => $canUpdateStatus ? 'sometimes|in:pending,in_progress,completed' : 'prohibited',
            'oil_changed' => $superTask->type === 'friteuse' ? 'nullable|boolean' : 'prohibited',
            'cleaned' => $superTask->type === 'friteuse' ? 'nullable|boolean' : 'prohibited',
            'friteuse_notes' => $superTask->type === 'friteuse' ? 'nullable|string' : 'prohibited',
            'organized' => $superTask->type === 'chambre_froide' ? 'nullable|boolean' : 'prohibited',
            'chambre_froide_notes' => $superTask->type === 'chambre_froide' ? 'nullable|string' : 'prohibited',
            'general_notes' => 'nullable|string',
        ]);

        // Normaliser assigned_to pour la comparaison (string vs int)
        $assignedToChanged = false;
        if (isset($validated['assigned_to'])) {
            $validated['assigned_to'] = (int) $validated['assigned_to'];
            $assignedToChanged = $validated['assigned_to'] !== (int) $superTask->assigned_to;
            
            if ($assignedToChanged) {
                $assignedUser = User::findOrFail($validated['assigned_to']);
                if ($assignedUser->store_id !== $user->store_id) {
                    return response()->json(['message' => 'L\'employé doit appartenir au même établissement'], 403);
                }
            } else {
                // Si l'employé n'a pas changé, ne pas l'inclure dans la mise à jour
                unset($validated['assigned_to']);
            }
        }

        // Vérifier qu'il n'y a pas déjà une super tâche de ce type pour la semaine si la date change
        $weekDateChanged = false;
        if (isset($validated['week_start_date'])) {
            // Normaliser la date au format Y-m-d
            $validated['week_start_date'] = Carbon::parse($validated['week_start_date'])->format('Y-m-d');
            
            // Normaliser les dates pour la comparaison (Carbon vs string)
            $newWeekDate = $validated['week_start_date'];
            $currentWeekDate = Carbon::parse($superTask->week_start_date)->format('Y-m-d');
            
            // Vérifier seulement si la date change réellement
            $weekDateChanged = $newWeekDate !== $currentWeekDate;
            
            if ($weekDateChanged) {
                $existing = SuperTask::where('store_id', $user->store_id)
                    ->where('type', $superTask->type)
                    ->where('week_start_date', $newWeekDate)
                    ->where('id', '!=', $superTask->id)
                    ->first();

                if ($existing) {
                    return response()->json(['message' => 'Une super tâche de ce type existe déjà pour cette semaine'], 409);
                }
            } else {
                // Si la date n'a pas changé, ne pas l'inclure dans la mise à jour
                unset($validated['week_start_date']);
            }
        }

        // Si le statut passe à "in_progress", enregistrer la date de début
        if (isset($validated['status']) && $validated['status'] === 'in_progress' && $superTask->status !== 'in_progress') {
            $validated['started_at'] = now();
        } elseif (isset($validated['status']) && $validated['status'] !== 'in_progress' && $validated['status'] !== 'completed') {
            $validated['started_at'] = null;
        }

        // Si le statut passe à "completed", enregistrer la date de fin
        if (isset($validated['status']) && $validated['status'] === 'completed' && $superTask->status !== 'completed') {
            $validated['completed_at'] = now();
            // S'assurer que started_at est défini si ce n'est pas déjà le cas
            if (!$superTask->started_at) {
                $validated['started_at'] = now();
            }
        } elseif (isset($validated['status']) && $validated['status'] !== 'completed') {
            $validated['completed_at'] = null;
        }

        // Si l'assignation change, envoyer une notification au nouvel employé
        $newAssignedToId = null;
        if ($assignedToChanged && isset($validated['assigned_to'])) {
            $newAssignedToId = $validated['assigned_to'];
        }
        
        $superTask->update($validated);
        $superTask->refresh();

        // Envoyer une notification si l'assignation a changé et que la super tâche n'est pas terminée
        if ($assignedToChanged && $newAssignedToId && $superTask->status !== 'completed') {
            try {
                $newAssignedUser = User::findOrFail($newAssignedToId);
                if ($newAssignedUser->email_verified_at) {
                    $newAssignedUser->notify(new SuperTaskAssignedNotification($superTask, $user));
                }
            } catch (\Exception $e) {
                Log::error('Erreur envoi notification super tâche réassignée: ' . $e->getMessage());
            }
        }

        $superTask->load(['assignedTo', 'assignedBy', 'store']);
        // Formater week_start_date
        $superTask = $this->formatSuperTask($superTask);

        return response()->json($superTask);
    }

    /**
     * Récupère une super tâche spécifique
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $superTask = SuperTask::where('id', $id);
        if ($user->store_id) {
            $superTask->where('store_id', $user->store_id);
        }

        // Si pas admin/chef/directeur, vérifier que c'est sa super tâche
        if (!$user->hasSharedPermission('tasks')) {
            $superTask->where('assigned_to', $user->id);
        }

        $superTask = $superTask->with(['assignedTo', 'assignedBy', 'store'])->firstOrFail();

        // Formater week_start_date
        $superTask = $this->formatSuperTask($superTask);

        return response()->json($superTask);
    }

    /**
     * Supprime une super tâche
     */
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

        $superTask = SuperTask::where('id', $id);
        if ($user->store_id) {
            $superTask->where('store_id', $user->store_id);
        }
        $superTask = $superTask->firstOrFail();

        $superTask->delete();

        return response()->json(['message' => 'Super tâche supprimée avec succès']);
    }
}
