<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeController extends Controller
{
    public function index(Request $request)
    {
        $query = Recipe::with('ingredients.product')->where('is_active', true);

        if ($request->filled('milieu')) {
            $m = $request->query('milieu');
            if (in_array($m, ['boucherie', 'cuisine', 'bar'], true)) {
                $query->where('milieu', $m);
            }
        }

        $recipes = $query->get()->map(function (Recipe $recipe) {
            return $this->decorateWithStockCompleteness($recipe);
        });

        return response()->json($recipes);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'milieu' => 'required|in:boucherie,cuisine,bar',
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
                $product = \App\Models\Product::query()->find($ingredient['product_id']);
                $recipe->ingredients()->create([
                    'product_id' => $ingredient['product_id'],
                    'quantity' => $ingredient['quantity'],
                    'unit' => $ingredient['unit'],
                    'product_name_snapshot' => $product?->name,
                ]);
            }

            $recipe->calculateCost();

            return response()->json($this->decorateWithStockCompleteness($recipe->load('ingredients.product')), 201);
        });
    }

    public function show(string $id)
    {
        $recipe = Recipe::with('ingredients.product')->findOrFail($id);
        return response()->json($this->decorateWithStockCompleteness($recipe));
    }

    public function update(Request $request, string $id)
    {
        $recipe = Recipe::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'milieu' => 'sometimes|required|in:boucherie,cuisine,bar',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'servings' => 'nullable|integer|min:1',
            'photo' => 'nullable|string',
            /** Remplace toute la composition si présent (édition complète des ingrédients). */
            'ingredients' => 'sometimes|array|min:1',
            'ingredients.*.product_id' => 'nullable|exists:products,id',
            'ingredients.*.product_name_snapshot' => 'nullable|string|max:255',
            'ingredients.*.quantity' => 'required|numeric|min:0.001',
            'ingredients.*.unit' => 'required|string',
        ]);

        if (isset($validated['ingredients'])) {
            foreach ($validated['ingredients'] as $i => $row) {
                $pid = $row['product_id'] ?? null;
                $snap = trim((string) ($row['product_name_snapshot'] ?? ''));
                if (empty($pid) && $snap === '') {
                    throw ValidationException::withMessages([
                        "ingredients.$i" => ['Indiquez un produit ou conservez le libellé si le produit n’existe plus en stock.'],
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($recipe, $validated) {
            $ingredientsPayload = $validated['ingredients'] ?? null;
            unset($validated['ingredients']);

            if (count($validated) > 0) {
                $recipe->update($validated);
            }

            if (is_array($ingredientsPayload)) {
                $recipe->ingredients()->delete();
                foreach ($ingredientsPayload as $ingredient) {
                    $productId = $ingredient['product_id'] ?? null;
                    $snapshot = isset($ingredient['product_name_snapshot'])
                        ? trim((string) $ingredient['product_name_snapshot']) : '';
                    if ($productId) {
                        $product = \App\Models\Product::query()->find($productId);
                        if ($product) {
                            $snapshot = $product->name;
                        }
                    }
                    $recipe->ingredients()->create([
                        'product_id' => $productId ?: null,
                        'quantity' => $ingredient['quantity'],
                        'unit' => $ingredient['unit'],
                        'product_name_snapshot' => $snapshot !== '' ? $snapshot : null,
                    ]);
                }
            }

            $recipe->calculateCost();

            return response()->json($this->decorateWithStockCompleteness($recipe->load('ingredients.product')));
        });
    }

    /**
     * Préparer une recette (diminue automatiquement le stock)
     */
    public function prepare(Request $request, string $id)
    {
        $recipe = Recipe::with('ingredients.product')->findOrFail($id);

        return DB::transaction(function () use ($recipe, $request) {
            $recipe = $this->decorateWithStockCompleteness($recipe);
            if ($recipe->ingredients_complete_in_stock !== true) {
                $missingNames = $recipe->ingredients_missing_names ?? [];
                $names = is_array($missingNames) && count($missingNames) > 0
                    ? implode(', ', $missingNames)
                    : 'non déterminés';
                return response()->json([
                    'error' => "Préparation bloquée : ingrédients incomplets en stock ({$names}).",
                ], 400);
            }

            // Vérifier le stock utilisable (hors produits / lots périmés)
            foreach ($recipe->ingredients as $ingredient) {
                if (!$ingredient->product_id) {
                    $ingredientName = $ingredient->product_name_snapshot ?? 'Produit supprimé';

                    return response()->json([
                        'error' => "Préparation bloquée : « {$ingredientName} » n’existe plus dans le stock (lien rompu).",
                    ], 400);
                }
                if (!$ingredient->product || !$ingredient->product->is_active) {
                    $ingredientName = $ingredient->product?->name
                        ?? ($ingredient->product_name_snapshot ?? "Produit #{$ingredient->product_id}");
                    return response()->json([
                        'error' => "Préparation bloquée : « {$ingredientName} » est introuvable ou retiré du stock actif.",
                    ], 400);
                }
                $usable = (float) $ingredient->product->usable_quantity;
                $need = (float) $ingredient->quantity;
                if (round($usable, 3) < round($need, 3)) {
                    return response()->json([
                        'error' => "Stock insuffisant (hors périmés) pour « {$ingredient->product->name} ». Disponible : {$usable}, besoin : {$need}.",
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
                'recipe' => $this->decorateWithStockCompleteness($recipe->load('ingredients.product'))
            ]);
        });
    }

    /**
     * Supprime une recette (les ingrédients sont supprimés en cascade).
     */
    public function destroy(string $id)
    {
        $recipe = Recipe::findOrFail($id);
        $recipe->delete();

        return response()->json(['message' => 'Recette supprimée avec succès']);
    }

    /**
     * Ajoute des indicateurs de complétude stock pour l'UI.
     */
    private function decorateWithStockCompleteness(Recipe $recipe): Recipe
    {
        $ingredients = $recipe->ingredients ?? collect();
        $total = $ingredients->count();
        $missingCount = 0;
        $missingNames = [];

        foreach ($ingredients as $ing) {
            $product = $ing->product;
            $need = (float) $ing->quantity;
            // Produit absent, retiré, ou stock utilisable insuffisant => ingrédient incomplet
            if (!$ing->product_id || !$product || !$product->is_active || (float) $product->usable_quantity < $need) {
                $missingCount++;
                $label = $product?->name
                    ?? ($ing->product_name_snapshot ? (string) $ing->product_name_snapshot : null)
                    ?? ($ing->product_id ? "Produit #{$ing->product_id}" : 'Ingrédient inconnu');
                $missingNames[] = $label;
            }
        }

        $recipe->setAttribute('ingredients_total_count', $total);
        $recipe->setAttribute('ingredients_missing_count', $missingCount);
        $recipe->setAttribute('ingredients_missing_names', array_values(array_unique($missingNames)));

        if ($total === 0) {
            $recipe->setAttribute('ingredients_complete_in_stock', true);
        } else {
            $recipe->setAttribute('ingredients_complete_in_stock', $missingCount === 0);
        }

        return $recipe;
    }
}
