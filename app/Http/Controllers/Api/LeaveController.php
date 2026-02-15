<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class LeaveController extends Controller
{
    /**
     * Liste des congés
     * Les employés voient leurs propres congés
     * Les admins voient tous les congés de leur établissement
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = Leave::with(['user', 'creator', 'approver', 'store']);

        // Filtrer par établissement
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne voit que ses propres congés
        if (!$user->hasSharedPermission('leaves')) {
            $query->where('user_id', $user->id);
        }

        // Filtres optionnels
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->where(function($q) use ($request) {
                $q->whereBetween('start_date', [$request->start_date, $request->end_date])
                  ->orWhereBetween('end_date', [$request->start_date, $request->end_date])
                  ->orWhere(function($q2) use ($request) {
                      $q2->where('start_date', '<=', $request->start_date)
                         ->where('end_date', '>=', $request->end_date);
                  });
            });
        }

        $leaves = $query->orderBy('start_date', 'desc')->get();

        return response()->json($leaves);
    }

    /**
     * Créer une demande de congé (employé) ou un congé (admin)
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $validated = $request->validate([
            'user_id' => 'sometimes|exists:users,id', // Requis si admin crée pour un employé
            'dates' => 'required|array|min:1',
            'dates.*' => 'required|date',
            'is_paid' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        // Déterminer si c'est une demande d'employé ou une création par admin
        $hasLeavesPermission = $user->hasSharedPermission('leaves');
        $targetUserId = $validated['user_id'] ?? $user->id;

        // Si admin crée pour un employé, vérifier que l'employé appartient au même établissement
        if ($hasLeavesPermission && isset($validated['user_id'])) {
            $targetUser = User::findOrFail($validated['user_id']);
            if ($user->store_id && $targetUser->store_id !== $user->store_id) {
                return response()->json(['message' => 'L\'employé n\'appartient pas à votre établissement'], 403);
            }
        }

        // Trier les dates et calculer start_date et end_date
        $dates = collect($validated['dates'])->map(function($date) {
            return Carbon::parse($date)->format('Y-m-d');
        })->sort()->values()->toArray();

        $startDate = Carbon::parse($dates[0]);
        $endDate = Carbon::parse($dates[count($dates) - 1]);
        $numberOfDays = Leave::calculateNumberOfDays($dates);

        // Créer le congé
        $leave = Leave::create([
            'user_id' => $targetUserId,
            'store_id' => $user->store_id,
            'dates' => $dates,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'number_of_days' => $numberOfDays,
            'status' => $hasLeavesPermission && isset($validated['user_id']) ? 'approved' : 'pending',
            'type' => $hasLeavesPermission && isset($validated['user_id']) ? 'created' : 'requested',
            'created_by' => $user->id,
            'approved_by' => ($hasLeavesPermission && isset($validated['user_id'])) ? $user->id : null,
            'approved_at' => ($hasLeavesPermission && isset($validated['user_id'])) ? now() : null,
            'is_paid' => $validated['is_paid'] ?? true, // Par défaut, les congés sont payés
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($leave->load(['user', 'creator', 'approver', 'store']), 201);
    }

    /**
     * Afficher un congé spécifique
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $leave = Leave::with(['user', 'creator', 'approver', 'store'])->findOrFail($id);

        // Vérifier que le congé appartient au même établissement
        if ($user->store_id && $leave->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        // Si l'utilisateur n'est pas admin/chef/directeur, il ne peut voir que ses propres congés
        if (!$user->hasSharedPermission('leaves') && $leave->user_id !== $user->id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($leave);
    }

    /**
     * Mettre à jour un congé (seulement les notes pour l'employé, tout pour l'admin)
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $leave = Leave::findOrFail($id);

        // Vérifier que le congé appartient au même établissement
        if ($user->store_id && $leave->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $hasLeavesPermission = $user->hasSharedPermission('leaves');
        $isCreator = $leave->created_by === $user->id;
        $isBeneficiary = $leave->user_id === $user->id;
        $canUpdateAsRequester = ($isCreator || $isBeneficiary) && $leave->status === 'pending';

        // Droit de modifier : permission congés OU (demandeur et congé encore en attente)
        if (!$hasLeavesPermission && !$canUpdateAsRequester) {
            return response()->json(['message' => 'Accès refusé. Vous ne pouvez modifier que votre propre demande de congé tant qu\'elle est en attente.'], 403);
        }

        $canUpdateAll = $hasLeavesPermission || $canUpdateAsRequester;

        // Validation selon les permissions (demandeur en attente peut envoyer dates, is_paid, notes)
        $validated = $request->validate([
            'dates' => $canUpdateAll ? 'sometimes|array|min:1' : 'prohibited',
            'dates.*' => $canUpdateAll ? 'required|date' : 'prohibited',
            'is_paid' => $canUpdateAll ? 'nullable|boolean' : 'prohibited',
            'notes' => 'nullable|string',
            'rejection_reason' => ($hasLeavesPermission && $leave->status === 'pending') ? 'nullable|string' : 'prohibited',
        ]);

        // Si les dates sont modifiées, recalculer
        if (isset($validated['dates'])) {
            $dates = collect($validated['dates'])->map(function($date) {
                return Carbon::parse($date)->format('Y-m-d');
            })->sort()->values()->toArray();

            $validated['start_date'] = Carbon::parse($dates[0]);
            $validated['end_date'] = Carbon::parse($dates[count($dates) - 1]);
            $validated['number_of_days'] = Leave::calculateNumberOfDays($dates);
        }

        $leave->update($validated);

        return response()->json($leave->load(['user', 'creator', 'approver', 'store']));
    }

    /**
     * Supprimer un congé
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $leave = Leave::findOrFail($id);

        // Vérifier que le congé appartient au même établissement
        if ($user->store_id && $leave->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        // Seuls admin/chef/directeur ou le créateur peuvent supprimer
        $hasLeavesPermission = $user->hasSharedPermission('leaves');
        if (!$hasLeavesPermission && $leave->created_by !== $user->id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $leave->delete();

        return response()->json(['message' => 'Congé supprimé avec succès']);
    }

    /**
     * Approuver un congé (admin uniquement)
     */
    public function approve(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin/chef/directeur peuvent approuver
        if (!$user->hasSharedPermission('leaves')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $leave = Leave::findOrFail($id);

        // Vérifier que le congé appartient au même établissement
        if ($user->store_id && $leave->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if ($leave->status !== 'pending') {
            return response()->json([
                'message' => 'Ce congé n\'est pas en attente de validation'
            ], 400);
        }

        // Seul l'admin peut approuver son propre congé ; chef/directeur ne peuvent approuver que les congés des autres
        if (!$user->isAdmin() && $leave->user_id === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas approuver votre propre demande de congé. Seul l\'administrateur peut le faire.'], 403);
        }

        $leave->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Congé approuvé avec succès',
            'leave' => $leave->load(['user', 'creator', 'approver', 'store'])
        ]);
    }

    /**
     * Rejeter un congé (admin uniquement pour son propre congé)
     */
    public function reject(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin/chef/directeur peuvent rejeter
        if (!$user->hasSharedPermission('leaves')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'rejection_reason' => 'nullable|string',
        ]);

        $leave = Leave::findOrFail($id);

        // Vérifier que le congé appartient au même établissement
        if ($user->store_id && $leave->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if ($leave->status !== 'pending') {
            return response()->json([
                'message' => 'Ce congé n\'est pas en attente de validation'
            ], 400);
        }

        // Seul l'admin peut rejeter son propre congé ; chef/directeur ne peuvent rejeter que les congés des autres
        if (!$user->isAdmin() && $leave->user_id === $user->id) {
            return response()->json(['message' => 'Vous ne pouvez pas rejeter votre propre demande de congé. Seul l\'administrateur peut le faire.'], 403);
        }

        $leave->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ]);

        return response()->json([
            'message' => 'Congé rejeté',
            'leave' => $leave->load(['user', 'creator', 'approver', 'store'])
        ]);
    }

    /**
     * Obtenir mes congés (pour un employé)
     */
    public function myLeaves(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $leaves = Leave::where('user_id', $user->id)
            ->with(['creator', 'approver', 'store'])
            ->orderBy('start_date', 'desc')
            ->get();

        return response()->json($leaves);
    }

    /**
     * Obtenir les demandes en attente (pour les admins)
     */
    public function pending(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seuls admin/chef/directeur peuvent voir les demandes en attente
        if (!$user->hasSharedPermission('leaves')) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $query = Leave::where('status', 'pending')
            ->with(['user', 'creator', 'store']);

        // Filtrer par établissement
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        $leaves = $query->orderBy('created_at', 'asc')->get();

        return response()->json($leaves);
    }

    /**
     * Nombre de congés approuvés/rejetés du demandeur non encore "vus" (pour le badge).
     */
    public function myDecidedUnseenCount(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $count = Leave::where('user_id', $user->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->whereNull('seen_by_user_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Marquer les congés approuvés/rejetés du demandeur comme "vus" (appelé à l'ouverture de la page Congés).
     */
    public function markDecidedSeen(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        Leave::where('user_id', $user->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->whereNull('seen_by_user_at')
            ->update(['seen_by_user_at' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
