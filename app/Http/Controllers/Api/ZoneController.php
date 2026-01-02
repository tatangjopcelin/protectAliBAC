<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    /**
     * Display a listing of the resource.
     * Nécessite une authentification et filtre par établissement.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $query = Zone::with('store');
        
        // Filtrer obligatoirement par établissement de l'utilisateur connecté (sécurité)
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucune zone pour la sécurité
            $query->whereRaw('1 = 0');
        }
        
        // Si l'utilisateur n'est pas admin, afficher seulement les zones actives
        if (!$user->isAdmin()) {
            $query->where('is_active', true);
        }

        $zones = $query->orderBy('name')->get();
        return response()->json($zones);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seul l'admin peut créer des zones
        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Accès refusé',
                'error' => 'Seul l\'administrateur peut créer des zones de stockage'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'shelf' => 'nullable|string|max:255',
            'bin' => 'nullable|string|max:255',
            'temperature' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['store_id'] = $user->store_id; // Forcer le store_id de l'utilisateur
        
        // Nettoyer les données : convertir les chaînes vides en null pour les champs nullable
        foreach (['type', 'description', 'shelf', 'bin'] as $field) {
            if (isset($validated[$field]) && $validated[$field] === '') {
                $validated[$field] = null;
            }
        }
        if (isset($validated['temperature']) && ($validated['temperature'] === '' || $validated['temperature'] === null)) {
            $validated['temperature'] = null;
        }
        
        $zone = Zone::create($validated);
        $zone->load('store');

        return response()->json([
            'message' => 'Zone créée avec succès',
            'zone' => $zone
        ], 201);
    }

    /**
     * Display the specified resource.
     * Nécessite une authentification et filtre par établissement.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $zone = Zone::with('store')
            ->where('id', $id);
        
        // Filtrer obligatoirement par établissement de l'utilisateur connecté (sécurité)
        if ($user->store_id) {
            $zone->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucune zone pour la sécurité
            $zone->whereRaw('1 = 0');
        }
        
        $zone = $zone->firstOrFail();
        
        // Si l'utilisateur n'est pas admin, vérifier que la zone est active
        if (!$user->isAdmin()) {
            if (!$zone->is_active) {
                return response()->json([
                    'message' => 'Zone non disponible'
                ], 404);
            }
        }
        
        return response()->json($zone);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seul l'admin peut modifier des zones
        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Accès refusé',
                'error' => 'Seul l\'administrateur peut modifier des zones de stockage'
            ], 403);
        }

        // Filtrer par établissement de l'utilisateur connecté (sécurité)
        $zone = Zone::where('id', $id);
        if ($user->store_id) {
            $zone->where('store_id', $user->store_id);
        } else {
            $zone->whereRaw('1 = 0');
        }
        $zone = $zone->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'shelf' => 'nullable|string|max:255',
            'bin' => 'nullable|string|max:255',
            'temperature' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
        ]);

        // Ne pas permettre de changer le store_id
        if (isset($validated['store_id'])) {
            unset($validated['store_id']);
        }
        $zone->update($validated);
        $zone->load('store');

        return response()->json([
            'message' => 'Zone mise à jour avec succès',
            'zone' => $zone
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = request()->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Seul l'admin peut supprimer des zones
        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Accès refusé',
                'error' => 'Seul l\'administrateur peut supprimer des zones de stockage'
            ], 403);
        }

        $zone = Zone::where('id', $id);
        if ($user->store_id) {
            $zone->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucune zone pour la sécurité
            $zone->whereRaw('1 = 0');
        }
        $zone = $zone->firstOrFail();

        // Vérifier si la zone contient des produits
        if ($zone->products()->count() > 0) {
            return response()->json([
                'message' => 'Impossible de supprimer',
                'error' => 'Cette zone contient des produits. Veuillez d\'abord déplacer ou supprimer les produits.'
            ], 422);
        }

        $zone->delete();

        return response()->json([
            'message' => 'Zone supprimée avec succès'
        ]);
    }

    /**
     * Met à jour la température d'une zone (automatique ou manuelle)
     */
    public function updateTemperature(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $zone = Zone::where('id', $id);
        if ($user->store_id) {
            $zone->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucune zone pour la sécurité
            $zone->whereRaw('1 = 0');
        }
        $zone = $zone->firstOrFail();

        $validated = $request->validate([
            'temperature' => 'required|numeric',
            'source' => 'nullable|string|in:manual,gps,api',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $zone->update([
            'temperature' => $validated['temperature']
        ]);
        $zone->load('store');

        return response()->json([
            'message' => 'Température mise à jour avec succès',
            'zone' => $zone,
            'temperature_data' => [
                'temperature' => $validated['temperature'],
                'source' => $validated['source'] ?? 'manual',
                'updated_at' => now()->toDateTimeString()
            ]
        ]);
    }
}
