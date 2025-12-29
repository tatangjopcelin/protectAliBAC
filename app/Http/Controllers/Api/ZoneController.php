<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = Zone::with('store');
        
        // Filtrer par magasin si spécifié
        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        
        // Filtrer seulement les zones actives si l'utilisateur n'est pas admin
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
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'shelf' => 'nullable|string|max:255',
            'bin' => 'nullable|string|max:255',
            'temperature' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
        ]);

        $zone = Zone::create($validated);
        $zone->load('store');

        return response()->json([
            'message' => 'Zone créée avec succès',
            'zone' => $zone
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $zone = Zone::with('store')->findOrFail($id);
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

        $zone = Zone::findOrFail($id);

        $validated = $request->validate([
            'store_id' => 'sometimes|exists:stores,id',
            'name' => 'sometimes|string|max:255',
            'type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'shelf' => 'nullable|string|max:255',
            'bin' => 'nullable|string|max:255',
            'temperature' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
        ]);

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

        $zone = Zone::findOrFail($id);

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

        $zone = Zone::findOrFail($id);

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
