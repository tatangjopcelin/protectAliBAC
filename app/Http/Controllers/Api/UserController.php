<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Liste tous les utilisateurs
     * Autorisé si l'utilisateur a une des permissions qui nécessitent de choisir un employé
     * (planning, leaves, time_entry, tasks pour les listes déroulantes). La page Utilisateurs reste réservée à l'admin.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $canList = $user->isAdmin()
            || $user->hasSharedPermission('planning')
            || $user->hasSharedPermission('leaves')
            || $user->hasSharedPermission('time_entry')
            || $user->hasSharedPermission('tasks');
        if (!$canList) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $query = User::query()->where('store_id', $user->store_id);

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('zone_id')) {
            $query->where('zone_id', $request->zone_id);
        }

        // Vérifier si la colonne max_overtime_hours existe
        $hasOvertimeColumn = \Schema::hasColumn('users', 'max_overtime_hours');
        
        $selectFields = ['id', 'name', 'email', 'role', 'zone_id', 'created_at'];
        if ($hasOvertimeColumn) {
            $selectFields[] = 'max_overtime_hours';
        }
        
        $users = $query->with('zone:id,name,type')
            ->select($selectFields)
            ->orderBy('name')
            ->get();
        
        // Si la colonne n'existe pas encore, ajouter une valeur par défaut
        if (!$hasOvertimeColumn) {
            $users = $users->map(function ($user) {
                $user->max_overtime_hours = 1.00;
                return $user;
            });
        }

        return response()->json($users);
    }

    /**
     * Affiche un utilisateur spécifique
     */
    public function show(string $id)
    {
        $currentUser = request()->user();
        
        // Seul l'admin peut voir les détails d'un utilisateur (page gestion)
        if (!$currentUser?->isAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $user = User::where('id', $id)
            ->where('store_id', $currentUser->store_id)
            ->firstOrFail();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'zone_id' => $user->zone_id,
            'max_overtime_hours' => $user->max_overtime_hours,
            'zone' => $user->zone ? [
                'id' => $user->zone->id,
                'name' => $user->zone->name,
                'type' => $user->zone->type,
            ] : null,
            'created_at' => $user->created_at,
        ]);
    }

    /**
     * Crée un nouvel utilisateur
     */
    public function store(Request $request)
    {
        // Seul l'admin peut créer des utilisateurs
        if (!$request->user()?->isAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => [
                'required',
                'string',
                Rule::in(['admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director', 'machine']),
            ],
            'zone_id' => 'nullable|exists:zones,id',
        ]);

        // Seul l'admin peut créer un utilisateur avec le rôle admin
        if ($validated['role'] === 'admin' && !$request->user()?->isAdmin()) {
            return response()->json([
                'message' => 'Accès refusé. Seul l\'admin peut créer un utilisateur avec le rôle admin.'
            ], 403);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'store_id' => $request->user()->store_id, // Utiliser le store_id de l'admin
            'zone_id' => $validated['zone_id'] ?? null,
        ]);
        $user->load('zone:id,name,type');

        return response()->json([
            'message' => 'Utilisateur créé avec succès',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'zone_id' => $user->zone_id,
                'max_overtime_hours' => $user->max_overtime_hours,
                'zone' => $user->zone ? [
                    'id' => $user->zone->id,
                    'name' => $user->zone->name,
                    'type' => $user->zone->type,
                ] : null,
            ],
        ], 201);
    }

    /**
     * Met à jour un utilisateur
     */
    public function update(Request $request, string $id)
    {
        $currentUser = $request->user();
        
        // Seul l'admin peut modifier des utilisateurs
        if (!$currentUser?->isAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $user = User::where('id', $id)
            ->where('store_id', $currentUser->store_id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($id),
            ],
            'password' => 'sometimes|string|min:8',
            'role' => [
                'sometimes',
                'string',
                Rule::in(['admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director', 'machine']),
            ],
            'zone_id' => 'nullable|exists:zones,id',
        ]);

        // Seul l'admin peut modifier le rôle d'un utilisateur
        if (isset($validated['role']) && !$request->user()?->isAdmin()) {
            return response()->json([
                'message' => 'Accès refusé. Seul l\'admin peut modifier le rôle d\'un utilisateur.'
            ], 403);
        }

        // Seul l'admin peut modifier le rôle d'un autre admin
        if (isset($validated['role']) && $user->isAdmin() && !$request->user()?->isAdmin()) {
            return response()->json([
                'message' => 'Accès refusé. Seul l\'admin peut modifier le rôle d\'un autre admin.'
            ], 403);
        }

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);
        $user->load('zone:id,name,type');

        return response()->json([
            'message' => 'Utilisateur mis à jour avec succès',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'zone_id' => $user->zone_id,
                'max_overtime_hours' => $user->max_overtime_hours,
                'zone' => $user->zone ? [
                    'id' => $user->zone->id,
                    'name' => $user->zone->name,
                    'type' => $user->zone->type,
                ] : null,
            ],
        ]);
    }

    /**
     * Met à jour uniquement le rôle d'un utilisateur (Admin uniquement)
     */
    public function updateRole(Request $request, string $id)
    {
        $currentUser = $request->user();
        
        // Seul l'admin peut modifier les rôles
        if (!$currentUser?->isAdmin()) {
            return response()->json([
                'message' => 'Accès refusé. Seul l\'admin peut modifier le rôle d\'un utilisateur.'
            ], 403);
        }

        $user = User::where('id', $id)
            ->where('store_id', $currentUser->store_id)
            ->firstOrFail();

        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                Rule::in(['admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director', 'machine']),
            ],
        ]);

        // Empêcher de modifier son propre rôle
        if ($user->id === $request->user()?->id) {
            return response()->json([
                'message' => 'Accès refusé. Vous ne pouvez pas modifier votre propre rôle.'
            ], 403);
        }

        // Note: Un admin peut maintenant modifier le rôle d'un autre admin
        // C'est une fonctionnalité de gestion importante pour les administrateurs
        $user->update(['role' => $validated['role']]);

        return response()->json([
            'message' => 'Rôle mis à jour avec succès',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Supprime un utilisateur
     */
    public function destroy(string $id)
    {
        $currentUser = request()->user();
        
        // Seul l'admin peut supprimer des utilisateurs
        if (!$currentUser?->isAdmin()) {
            return response()->json(['message' => 'Accès refusé. Seul l\'admin peut supprimer des utilisateurs.'], 403);
        }

        $user = User::where('id', $id)
            ->where('store_id', $currentUser->store_id)
            ->firstOrFail();

        // Empêcher de supprimer soi-même
        if ($user->id === request()->user()?->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.'
            ], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Utilisateur supprimé avec succès'], 200);
    }

    /**
     * Met à jour la limite d'heures supplémentaires pour tous les utilisateurs
     */
    public function updateOvertimeLimitForAll(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seul l'admin peut modifier les limites d'heures supplémentaires
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Accès refusé. Seul l\'admin peut modifier les limites d\'heures supplémentaires.'], 403);
        }

        $validated = $request->validate([
            'max_overtime_hours' => 'required|numeric|min:0|max:24',
        ]);

        $updatedCount = User::where('store_id', $user->store_id)->update([
            'max_overtime_hours' => $validated['max_overtime_hours']
        ]);

        return response()->json([
            'message' => 'Limite d\'heures supplémentaires mise à jour pour tous les utilisateurs',
            'max_overtime_hours' => $validated['max_overtime_hours'],
            'users_updated' => $updatedCount,
        ]);
    }

    /**
     * Met à jour la limite d'heures supplémentaires pour un utilisateur spécifique
     */
    public function updateOvertimeLimit(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seul l'admin peut modifier les limites d'heures supplémentaires
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Accès refusé. Seul l\'admin peut modifier les limites d\'heures supplémentaires.'], 403);
        }

        $validated = $request->validate([
            'max_overtime_hours' => 'required|numeric|min:0|max:24',
        ]);

        // Mettre à jour pour un utilisateur spécifique du même établissement
        $targetUser = User::where('id', $id)
            ->where('store_id', $user->store_id)
            ->firstOrFail();
        $targetUser->max_overtime_hours = $validated['max_overtime_hours'];
        $targetUser->save();

        return response()->json([
            'message' => 'Limite d\'heures supplémentaires mise à jour',
            'user' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'max_overtime_hours' => $targetUser->max_overtime_hours,
            ],
        ]);
    }

    /**
     * Récupérer les permissions partagées d'un utilisateur (admin uniquement, pour director/chef)
     */
    public function getSharedPermissions(string $id)
    {
        $currentUser = request()->user();
        if (!$currentUser?->isAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $user = User::where('id', $id)
            ->where('store_id', $currentUser->store_id)
            ->firstOrFail();

        if (!in_array($user->role, ['director', 'chef'], true)) {
            return response()->json([
                'message' => 'Les permissions partagées ne s\'appliquent qu\'aux rôles Directeur et Chef.',
                'permissions' => null,
            ], 400);
        }

        return response()->json([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'role' => $user->role,
            'permissions' => $user->getSharedPermissionOverrides(),
        ]);
    }

    /**
     * Mettre à jour les permissions partagées d'un utilisateur (admin uniquement)
     */
    public function updateSharedPermissions(Request $request, string $id)
    {
        $currentUser = request()->user();
        if (!$currentUser?->isAdmin()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $user = User::where('id', $id)
            ->where('store_id', $currentUser->store_id)
            ->firstOrFail();

        if (!in_array($user->role, ['director', 'chef'], true)) {
            return response()->json(['message' => 'Les permissions partagées ne s\'appliquent qu\'aux rôles Directeur et Chef.'], 400);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.planning' => 'boolean',
            'permissions.leaves' => 'boolean',
            'permissions.time_entry' => 'boolean',
            'permissions.tasks' => 'boolean',
        ]);

        $overrides = [];
        foreach (User::SHARED_PERMISSIONS as $key) {
            if (array_key_exists($key, $validated['permissions'])) {
                $overrides[$key] = (bool) $validated['permissions'][$key];
            }
        }
        $user->shared_permission_overrides = $overrides;
        $user->save();

        return response()->json([
            'message' => 'Permissions mises à jour',
            'permissions' => $user->getSharedPermissionOverrides(),
        ]);
    }
}
