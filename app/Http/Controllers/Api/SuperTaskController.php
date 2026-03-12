<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuperTask;
use App\Models\User;
use App\Notifications\SuperTaskAssignedNotification;
use App\Services\NotificationService;
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
     * Retourne une super tâche en tableau avec week_start_date en Y-m-d (évite décalage -1 jour en front)
     */
    private function superTaskToArray(SuperTask $superTask): array
    {
        $arr = $superTask->toArray();
        $arr['week_start_date'] = Carbon::parse($superTask->week_start_date)->format('Y-m-d');
        return $arr;
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

        // Ordre : de la super tâche la plus proche à exécuter à la plus lointaine
        $superTasks = $query->orderBy('week_start_date', 'asc')
            ->orderBy('type', 'asc')
            ->get();

        $superTasks = $this->formatSuperTasks($superTasks);

        return response()->json($superTasks->map(fn ($t) => $this->superTaskToArray($t)));
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
            // Les prochaines super tâches à faire en premier
            ->orderBy('week_start_date', 'asc')
            ->get();

        $superTasks = $this->formatSuperTasks($superTasks);

        return response()->json($superTasks->map(fn ($t) => $this->superTaskToArray($t)));
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
            'day_of_week' => 'nullable|integer|min:1|max:7',
        ]);

        // Normaliser au lundi de la semaine sélectionnée (permettre plusieurs semaines)
        $validated['week_start_date'] = Carbon::parse($validated['week_start_date'])
            ->startOfWeek(Carbon::MONDAY)
            ->format('Y-m-d');

        // Vérifier que l'utilisateur assigné appartient au même établissement
        $assignedUser = User::findOrFail($validated['assigned_to']);
        if ($assignedUser->store_id !== $user->store_id) {
            return response()->json(['message' => 'L\'employé doit appartenir au même établissement'], 403);
        }

        $today = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $minWeek = $today->copy()->subWeeks(1)->format('Y-m-d');   // jusqu'à 1 semaine en arrière
        $maxWeek = $today->copy()->addWeeks(52)->format('Y-m-d');   // jusqu'à 52 semaines à l'avance

        if ($validated['week_start_date'] < $minWeek || $validated['week_start_date'] > $maxWeek) {
            return response()->json([
                'message' => 'La semaine doit être entre ' . Carbon::parse($minWeek)->format('d/m/Y') . ' et ' . Carbon::parse($maxWeek)->format('d/m/Y')
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

        $superTask = SuperTask::create([
            'store_id' => $user->store_id,
            'type' => $validated['type'],
            'assigned_to' => $validated['assigned_to'],
            'assigned_by' => $user->id,
            'week_start_date' => $validated['week_start_date'],
            'day_of_week' => isset($validated['day_of_week']) ? (int) $validated['day_of_week'] : null,
            'status' => 'pending',
        ]);

        // Charger les relations
        $superTask->load(['assignedTo', 'assignedBy', 'store']);
        $this->notifySuperTaskAssigned($superTask, $assignedUser, $user);

        $superTask = $this->formatSuperTask($superTask);

        return response()->json($this->superTaskToArray($superTask), 201);
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
            'day_of_week' => $canUpdateAll ? 'nullable|integer|min:1|max:7' : 'prohibited',
            'status' => $canUpdateStatus ? 'sometimes|in:pending,in_progress,completed,absent' : 'prohibited',
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
            // Normaliser au lundi de la semaine
            $validated['week_start_date'] = Carbon::parse($validated['week_start_date'])
                ->startOfWeek(Carbon::MONDAY)
                ->format('Y-m-d');

            $today = Carbon::now()->startOfWeek(Carbon::MONDAY);
            $minWeek = $today->copy()->subWeeks(1)->format('Y-m-d');
            $maxWeek = $today->copy()->addWeeks(52)->format('Y-m-d');
            if ($validated['week_start_date'] < $minWeek || $validated['week_start_date'] > $maxWeek) {
                return response()->json([
                    'message' => 'La semaine doit être entre ' . Carbon::parse($minWeek)->format('d/m/Y') . ' et ' . Carbon::parse($maxWeek)->format('d/m/Y')
                ], 400);
            }
            
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
                $this->notifySuperTaskAssigned($superTask, $newAssignedUser, $user);
            } catch (\Exception $e) {
                Log::error('Erreur envoi notification super tâche réassignée: ' . $e->getMessage());
            }
        }

        $superTask->load(['assignedTo', 'assignedBy', 'store']);
        $superTask = $this->formatSuperTask($superTask);

        return response()->json($this->superTaskToArray($superTask));
    }

    /**
     * Programme des super tâches sur plusieurs mois : un jour fixe par semaine (ex. tous les lundis ou tous les samedis).
     * Crée une super tâche par semaine sur la période, sauf si une existe déjà pour ce type et cette semaine.
     *
     * Body: type, day_of_week (1=lundi … 7=dimanche), months (1-12), assigned_to
     */
    public function schedule(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        if (!$user->hasSharedPermission('tasks')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'type' => 'required|in:friteuse,chambre_froide',
            'day_of_week' => 'required|integer|min:1|max:7', // 1 = lundi, 7 = dimanche
            'months' => 'required|integer|min:1|max:12',
            'assigned_to' => 'required|exists:users,id',
        ]);

        $assignedUser = User::findOrFail($validated['assigned_to']);
        if ($assignedUser->store_id !== $user->store_id) {
            return response()->json(['message' => 'L\'employé doit appartenir au même établissement'], 403);
        }

        $startMonday = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $endDate = $startMonday->copy()->addMonths((int) $validated['months']);
        $created = [];
        $skipped = 0;

        $currentMonday = $startMonday->copy();
        while ($currentMonday->lt($endDate)) {
            $weekStart = $currentMonday->format('Y-m-d');
            $exists = SuperTask::where('store_id', $user->store_id)
                ->where('type', $validated['type'])
                ->where('week_start_date', $weekStart)
                ->exists();

            if (!$exists) {
                $superTask = SuperTask::create([
                    'store_id' => $user->store_id,
                    'type' => $validated['type'],
                    'assigned_to' => $assignedUser->id,
                    'assigned_by' => $user->id,
                    'week_start_date' => $weekStart,
                    'day_of_week' => (int) $validated['day_of_week'],
                    'status' => 'pending',
                ]);
                $superTask->load(['assignedTo', 'assignedBy', 'store']);
                $this->notifySuperTaskAssigned($superTask, $assignedUser, $user);
                $created[] = $this->superTaskToArray($this->formatSuperTask($superTask));
            } else {
                $skipped++;
            }
            $currentMonday->addWeek();
        }

        return response()->json([
            'message' => count($created) . ' super tâche(s) créée(s)' . ($skipped > 0 ? ', ' . $skipped . ' déjà existante(s) ignorée(s).' : '.'),
            'created' => count($created),
            'skipped' => $skipped,
            'super_tasks' => $created,
        ], 201);
    }

    /**
     * Envoie email + push + SMS à l'employé assigné pour une super tâche.
     */
    private function notifySuperTaskAssigned(SuperTask $superTask, User $assignedUser, User $creator): void
    {
        try {
            if ($assignedUser->email_verified_at) {
                $assignedUser->notify(new SuperTaskAssignedNotification($superTask, $creator));
            }
        } catch (\Exception $e) {
            Log::error('Erreur envoi notification super tâche assignée: ' . $e->getMessage());
        }
        $typeLabel = $superTask->type === 'friteuse' ? 'Friteuse' : 'Chambre froide';
        $weekLabel = Carbon::parse($superTask->week_start_date)->locale('fr')->format('d/m/Y');
        $shortMessage = "Super tâche {$typeLabel} assignée pour la semaine du {$weekLabel}. Consultez l'app Brole.";
        app(NotificationService::class)->sendNotification(
            $assignedUser,
            'super_task_assigned',
            'Super tâche assignée',
            $shortMessage,
            [
                'route' => '/tabs/super-tasks',
                'screen' => 'super-tasks',
                'super_task_id' => (string) $superTask->id,
                'type' => $superTask->type,
            ],
            'all'
        );
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
        $superTask = $this->formatSuperTask($superTask);

        return response()->json($this->superTaskToArray($superTask));
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
