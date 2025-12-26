<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    public function index()
    {
        return response()->json(Recipe::with('ingredients.product')->where('is_active', true)->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'servings' => 'nullable|integer|min:1',
            'photo' => 'nullable|string',
            'ingredients' => 'required|array|min:1',
            'ingredients.*.product_id' => 'required|exists:products,id',
            'ingredients.*.quantity' => 'required|numeric|min:0.001',
            'ingredients.*.unit' => 'required|string',
        ]);

        return DB::transaction(function () use ($validated) {
            $recipe = Recipe::create($validated);

            foreach ($validated['ingredients'] as $ingredient) {
                $recipe->ingredients()->create($ingredient);
            }

            $recipe->calculateCost();

            return response()->json($recipe->load('ingredients.product'), 201);
        });
    }

    public function show(string $id)
    {
        return response()->json(Recipe::with('ingredients.product')->findOrFail($id));
    }

    public function update(Request $request, string $id)
    {
        $recipe = Recipe::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'servings' => 'nullable|integer|min:1',
            'photo' => 'nullable|string',
        ]);

        $recipe->update($validated);
        $recipe->calculateCost();

        return response()->json($recipe->load('ingredients.product'));
    }

    /**
     * Préparer une recette (diminue automatiquement le stock)
     */
    public function prepare(Request $request, string $id)
    {
        $recipe = Recipe::with('ingredients.product')->findOrFail($id);

        return DB::transaction(function () use ($recipe, $request) {
            // Vérifier que tous les ingrédients sont disponibles
            foreach ($recipe->ingredients as $ingredient) {
                if ($ingredient->product->quantity < $ingredient->quantity) {
                    return response()->json([
                        'error' => "Stock insuffisant pour {$ingredient->product->name}"
                    ], 400);
                }
            }

            // Créer les mouvements de stock pour chaque ingrédient
            foreach ($recipe->ingredients as $ingredient) {
                StockMovement::create([
                    'product_id' => $ingredient->product_id,
                    'user_id' => $request->user()?->id,
                    'type' => 'used',
                    'quantity' => $ingredient->quantity,
                    'recipe_id' => $recipe->id,
                    'notes' => "Utilisé pour la recette: {$recipe->name}",
                ]);

                // Diminuer le stock
                $ingredient->product->quantity -= $ingredient->quantity;
                $ingredient->product->save();
            }

            return response()->json([
                'message' => "Recette '{$recipe->name}' préparée avec succès",
                'recipe' => $recipe->load('ingredients.product')
            ]);
        });
    }
}
