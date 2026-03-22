<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Liste des catégories (tri par nom).
     */
    public function index(Request $request)
    {
        $query = Category::query()->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    /**
     * Création d'une catégorie.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'color' => 'nullable|string|max:32',
            'is_active' => 'sometimes|boolean',
        ]);

        $category = Category::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? null,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        ]);

        return response()->json($category, 201);
    }

    /**
     * Détail d'une catégorie.
     */
    public function show(string $id)
    {
        $category = Category::find($id);
        if (!$category) {
            return response()->json(['message' => 'Catégorie introuvable'], 404);
        }

        return response()->json($category);
    }

    /**
     * Mise à jour d'une catégorie.
     */
    public function update(Request $request, string $id)
    {
        $category = Category::find($id);
        if (!$category) {
            return response()->json(['message' => 'Catégorie introuvable'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'color' => 'nullable|string|max:32',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->fill($validated);
        $category->save();

        return response()->json($category);
    }

    /**
     * Suppression (refusée si des produits utilisent cette catégorie).
     */
    public function destroy(string $id)
    {
        $category = Category::find($id);
        if (!$category) {
            return response()->json(['message' => 'Catégorie introuvable'], 404);
        }

        if (Product::where('category_id', $category->id)->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer cette catégorie : des produits y sont encore rattachés.',
                'error' => 'category_has_products',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Catégorie supprimée'], 200);
    }
}
