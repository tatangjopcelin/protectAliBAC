<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AccountSettingsController extends Controller
{
    /**
     * Obtenir les informations du compte de l'utilisateur connecté
     */
    public function show(Request $request)
    {
        $user = $request->user()->load('store');
        
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'role' => $user->role,
            'zone_id' => $user->zone_id,
            'store_id' => $user->store_id,
            'store' => $user->store ? [
                'id' => $user->store->id,
                'name' => $user->store->name,
                'establishment_code' => $user->store->establishment_code,
            ] : null,
        ]);
    }

    /**
     * Mettre à jour le profil de l'utilisateur (nom, email, téléphone)
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'phone' => 'nullable|string|max:20',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profil mis à jour avec succès',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ]);
    }

    /**
     * Mettre à jour la photo de profil (base64)
     */
    public function updateAvatar(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'avatar' => 'nullable|string',
        ]);

        $user->avatar = $validated['avatar'] ?? null;
        $user->save();

        return response()->json([
            'message' => 'Photo de profil mise à jour',
            'avatar' => $user->avatar,
        ]);
    }

    /**
     * Changer le mot de passe
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Vérifier le mot de passe actuel
        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Le mot de passe actuel est incorrect',
                'errors' => [
                    'current_password' => ['Le mot de passe actuel est incorrect']
                ]
            ], 422);
        }

        // Mettre à jour le mot de passe
        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'message' => 'Mot de passe modifié avec succès',
        ]);
    }
}
