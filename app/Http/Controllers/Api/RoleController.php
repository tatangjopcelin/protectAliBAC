<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Obtenir toutes les permissions disponibles
     */
    public function permissions(Request $request)
    {
        $permissions = Permission::orderBy('resource')->orderBy('action')->get();
        return response()->json($permissions);
    }

    /**
     * Obtenir les permissions d'un rôle spécifique
     */
    public function rolePermissions(string $role)
    {
        $validRoles = ['admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director'];
        if (!in_array($role, $validRoles)) {
            return response()->json(['message' => 'Rôle invalide'], 400);
        }

        $permissions = RolePermission::where('role', $role)
            ->with('permission')
            ->get()
            ->pluck('permission');

        return response()->json([
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Assigner une permission à un rôle
     */
    public function assignPermission(Request $request, string $role)
    {
        $validRoles = ['admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director'];
        if (!in_array($role, $validRoles)) {
            return response()->json(['message' => 'Rôle invalide'], 400);
        }

        // Seul l'admin peut modifier les permissions
        if (!$request->user()?->isAdmin()) {
            return response()->json(['message' => 'Accès refusé. Seul l\'admin peut modifier les permissions.'], 403);
        }

        $validated = $request->validate([
            'permission_id' => 'required|exists:permissions,id',
        ]);

        $rolePermission = RolePermission::firstOrCreate([
            'role' => $role,
            'permission_id' => $validated['permission_id'],
        ]);

        return response()->json([
            'message' => 'Permission assignée avec succès',
            'role_permission' => $rolePermission->load('permission'),
        ], 201);
    }

    /**
     * Retirer une permission d'un rôle
     */
    public function revokePermission(Request $request, string $role, string $permissionId)
    {
        $validRoles = ['admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director'];
        if (!in_array($role, $validRoles)) {
            return response()->json(['message' => 'Rôle invalide'], 400);
        }

        // Seul l'admin peut modifier les permissions
        if (!$request->user()?->isAdmin()) {
            return response()->json(['message' => 'Accès refusé. Seul l\'admin peut modifier les permissions.'], 403);
        }

        $rolePermission = RolePermission::where('role', $role)
            ->where('permission_id', $permissionId)
            ->first();

        if (!$rolePermission) {
            return response()->json(['message' => 'Permission non trouvée pour ce rôle'], 404);
        }

        $rolePermission->delete();

        return response()->json(['message' => 'Permission retirée avec succès'], 200);
    }

    /**
     * Obtenir les utilisateurs d'un rôle spécifique
     */
    public function usersByRole(string $role)
    {
        $validRoles = ['admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director'];
        if (!in_array($role, $validRoles)) {
            return response()->json(['message' => 'Rôle invalide'], 400);
        }

        $users = User::where('role', $role)->get(['id', 'name', 'email', 'role']);

        return response()->json([
            'role' => $role,
            'users' => $users,
        ]);
    }

    /**
     * Vérifier les permissions de l'utilisateur actuel
     */
    public function myPermissions(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        if ($user->isAdmin()) {
            $permissions = Permission::all();
        } else {
            $permissions = Permission::whereHas('rolePermissions', function ($query) use ($user) {
                $query->where('role', $user->role);
            })->get();
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ],
            'permissions' => $permissions,
        ]);
    }
}
