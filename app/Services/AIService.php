<?php

namespace App\Services;

use App\Models\AISuggestion;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class AIService
{
    /**
     * Génère des suggestions de recettes basées sur les produits qui expirent bientôt (avec IA)
     */
    public function suggestRecipesForExpiringProducts(int $days = 3): array
    {
        $expiringProducts = Product::where('is_active', true)
            ->where('expiration_date', '<=', Carbon::now()->addDays($days))
            ->where('expiration_date', '>=', Carbon::today())
            ->where('quantity', '>', 0)
            ->with(['category', 'zone', 'supplier'])
            ->get();

        if ($expiringProducts->isEmpty()) {
            return [];
        }

        $suggestions = [];

        foreach ($expiringProducts as $product) {
            // Trouver des recettes existantes qui utilisent ce produit
            $existingRecipes = Recipe::whereHas('ingredients', function ($query) use ($product) {
                $query->where('product_id', $product->id);
            })->with('ingredients.product')->get();

            // Utiliser l'IA pour suggérer des recettes créatives
            $aiSuggestions = $this->getAIRecipeSuggestions($product, $existingRecipes);

            foreach ($aiSuggestions as $aiSuggestion) {
                $suggestion = AISuggestion::create([
                    'type' => 'recipe',
                    'title' => $aiSuggestion['title'],
                    'description' => $aiSuggestion['description'],
                    'data' => $aiSuggestion['data'],
                    'product_id' => $product->id,
                    'recipe_id' => $aiSuggestion['recipe_id'] ?? null,
                    'confidence_score' => $aiSuggestion['confidence'] ?? 0.7,
                    'status' => 'pending',
                ]);

                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Utilise OpenAI pour générer des suggestions de recettes intelligentes
     */
    private function getAIRecipeSuggestions(Product $product, $existingRecipes): array
    {
        try {
            $productInfo = [
                'name' => $product->name,
                'category' => $product->category?->name ?? 'Non catégorisé',
                'quantity' => $product->quantity,
                'unit' => $product->unit,
                'expires_in_days' => abs($product->daysUntilExpiration()),
                'zone' => $product->zone?->name ?? 'Non défini',
            ];

            $existingRecipesList = $existingRecipes->map(function ($recipe) {
                return $recipe->name;
            })->implode(', ');

            $prompt = "Tu es un chef expert dans un restaurant. Le produit suivant expire bientôt et doit être utilisé rapidement:\n\n";
            $prompt .= "Produit: {$productInfo['name']}\n";
            $prompt .= "Catégorie: {$productInfo['category']}\n";
            $prompt .= "Quantité disponible: {$productInfo['quantity']} {$productInfo['unit']}\n";
            $prompt .= "Expire dans: {$productInfo['expires_in_days']} jour(s)\n";
            $prompt .= "Zone: {$productInfo['zone']}\n\n";
            
            if ($existingRecipesList) {
                $prompt .= "Recettes existantes utilisant ce produit: {$existingRecipesList}\n\n";
            }
            
            $prompt .= "Suggère 2-3 recettes créatives et pratiques pour utiliser ce produit avant qu'il n'expire. ";
            $prompt .= "Réponds en JSON avec ce format:\n";
            $prompt .= '{"suggestions": [{"title": "Nom de la recette", "description": "Description détaillée", "ingredients": ["ingrédient1", "ingrédient2"], "confidence": 0.8}]}';

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un chef expert qui aide à réduire le gaspillage alimentaire en suggérant des recettes créatives.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            $aiData = json_decode($content, true);

            $suggestions = [];
            if (isset($aiData['suggestions']) && is_array($aiData['suggestions'])) {
                foreach ($aiData['suggestions'] as $suggestion) {
                    $suggestions[] = [
                        'title' => $suggestion['title'] ?? "Recette avec {$product->name}",
                        'description' => $suggestion['description'] ?? "Utilisez {$product->name} qui expire bientôt",
                        'data' => [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'expires_in_days' => $productInfo['expires_in_days'],
                            'suggested_ingredients' => $suggestion['ingredients'] ?? [],
                            'ai_generated' => true,
                        ],
                        'confidence' => $suggestion['confidence'] ?? 0.7,
                    ];
                }
            }

            // Ajouter aussi les recettes existantes avec analyse IA
            foreach ($existingRecipes as $recipe) {
                $allAvailable = true;
                $missingIngredients = [];

                foreach ($recipe->ingredients as $ingredient) {
                    if ($ingredient->product->quantity < $ingredient->quantity) {
                        $allAvailable = false;
                        $missingIngredients[] = $ingredient->product->name;
                    }
                }

                $suggestions[] = [
                    'title' => "Recette existante: {$recipe->name}",
                    'description' => "Le produit {$product->name} expire dans {$productInfo['expires_in_days']} jour(s). Cette recette utilise ce produit.",
                    'data' => [
                        'recipe_id' => $recipe->id,
                        'product_id' => $product->id,
                        'expires_in_days' => $productInfo['expires_in_days'],
                        'all_ingredients_available' => $allAvailable,
                        'missing_ingredients' => $missingIngredients,
                        'ai_generated' => false,
                    ],
                    'recipe_id' => $recipe->id,
                    'confidence' => $allAvailable ? 0.9 : 0.6,
                ];
            }

            return $suggestions;

        } catch (\Exception $e) {
            Log::error('Erreur OpenAI pour suggestions de recettes: ' . $e->getMessage());
            
            // Fallback: suggestions basiques sans IA
            $suggestions = [];
            foreach ($existingRecipes as $recipe) {
                $suggestions[] = [
                    'title' => "Recette: {$recipe->name}",
                    'description' => "Le produit {$product->name} expire bientôt. Cette recette utilise ce produit.",
                    'data' => ['recipe_id' => $recipe->id, 'product_id' => $product->id],
                    'recipe_id' => $recipe->id,
                    'confidence' => 0.7,
                ];
            }
            return $suggestions;
        }
    }

    /**
     * Prédit la consommation future d'un produit avec analyse IA avancée
     */
    public function predictConsumption(int $productId, int $days = 7): array
    {
        $product = Product::findOrFail($productId);

        // Récupérer l'historique des sorties des 90 derniers jours
        $movements = StockMovement::where('product_id', $productId)
            ->whereIn('type', ['used', 'exit', 'wasted'])
            ->where('created_at', '>=', Carbon::now()->subDays(90))
            ->orderBy('created_at')
            ->get();

        if ($movements->isEmpty()) {
            return [
                'product_id' => $productId,
                'product_name' => $product->name,
                'predicted_consumption' => 0,
                'confidence' => 0.1,
                'message' => 'Pas assez de données historiques',
                'ai_analysis' => null,
            ];
        }

        // Préparer les données pour l'IA
        $consumptionByDay = $movements->groupBy(function ($item) {
            return $item->created_at->format('Y-m-d');
        })->map(function ($dayMovements) {
            return $dayMovements->sum('quantity');
        });

        $consumptionByDayOfWeek = [];
        foreach ($movements as $movement) {
            $dayOfWeek = $movement->created_at->dayOfWeek;
            if (!isset($consumptionByDayOfWeek[$dayOfWeek])) {
                $consumptionByDayOfWeek[$dayOfWeek] = 0;
            }
            $consumptionByDayOfWeek[$dayOfWeek] += $movement->quantity;
        }

        // Utiliser l'IA pour analyser les patterns
        $aiAnalysis = $this->getAIConsumptionAnalysis($product, $consumptionByDay, $consumptionByDayOfWeek, $days);

        // Calculs statistiques de base
        $totalConsumption = $movements->sum('quantity');
        $daysOfData = $consumptionByDay->count();
        $averageDailyConsumption = $totalConsumption / max($daysOfData, 1);
        $predictedConsumption = $averageDailyConsumption * $days;

        // Ajuster avec l'analyse IA si disponible
        if ($aiAnalysis && isset($aiAnalysis['predicted_consumption'])) {
            $predictedConsumption = $aiAnalysis['predicted_consumption'];
        }

        $confidence = min(0.9, 0.3 + ($daysOfData / 90) * 0.6);
        if ($aiAnalysis && isset($aiAnalysis['confidence'])) {
            $confidence = $aiAnalysis['confidence'];
        }

        return [
            'product_id' => $productId,
            'product_name' => $product->name,
            'predicted_consumption' => round($predictedConsumption, 3),
            'average_daily_consumption' => round($averageDailyConsumption, 3),
            'days' => $days,
            'confidence' => round($confidence, 2),
            'current_stock' => $product->quantity,
            'will_run_out' => $product->quantity < $predictedConsumption,
            'days_until_out_of_stock' => $product->quantity > 0 ? round($product->quantity / max($averageDailyConsumption, 0.001)) : 0,
            'ai_analysis' => $aiAnalysis,
            'patterns' => [
                'consumption_by_day_of_week' => $consumptionByDayOfWeek,
                'trend' => $this->calculateTrend($consumptionByDay),
            ],
        ];
    }

    /**
     * Utilise OpenAI pour analyser les patterns de consommation
     */
    private function getAIConsumptionAnalysis(Product $product, $consumptionByDay, $consumptionByDayOfWeek, int $days): ?array
    {
        try {
            $dataSummary = [
                'total_days' => count($consumptionByDay),
                'average_daily' => round($consumptionByDay->avg(), 3),
                'max_daily' => round($consumptionByDay->max(), 3),
                'min_daily' => round($consumptionByDay->min(), 3),
                'consumption_by_day_of_week' => $consumptionByDayOfWeek,
            ];

            $prompt = "Analyse les données de consommation suivantes pour un produit de restaurant et prédits la consommation pour les {$days} prochains jours.\n\n";
            $prompt .= "Produit: {$product->name} ({$product->category?->name})\n";
            $prompt .= "Stock actuel: {$product->quantity} {$product->unit}\n";
            $prompt .= "Données historiques:\n";
            $prompt .= "- Nombre de jours de données: {$dataSummary['total_days']}\n";
            $prompt .= "- Consommation moyenne quotidienne: {$dataSummary['average_daily']} {$product->unit}\n";
            $prompt .= "- Consommation max/jour: {$dataSummary['max_daily']} {$product->unit}\n";
            $prompt .= "- Consommation min/jour: {$dataSummary['min_daily']} {$product->unit}\n";
            $prompt .= "- Consommation par jour de la semaine: " . json_encode($consumptionByDayOfWeek) . "\n\n";
            $prompt .= "Prédits la consommation pour les {$days} prochains jours en tenant compte des patterns (weekends, tendances, etc.).\n";
            $prompt .= "Réponds en JSON: {\"predicted_consumption\": nombre, \"confidence\": 0.0-1.0, \"reasoning\": \"explication\"}";

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en analyse de données et prédiction de consommation pour restaurants.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            return json_decode($content, true);

        } catch (\Exception $e) {
            Log::error('Erreur OpenAI pour prédiction consommation: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Calcule la tendance de consommation
     */
    private function calculateTrend($consumptionByDay): string
    {
        if (count($consumptionByDay) < 7) {
            return 'insufficient_data';
        }

        $recent = $consumptionByDay->take(7)->avg();
        $previous = $consumptionByDay->skip(7)->take(7)->avg();

        if ($recent > $previous * 1.1) {
            return 'increasing';
        } elseif ($recent < $previous * 0.9) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }

    /**
     * Génère des suggestions de commandes avec analyse IA
     */
    public function suggestOrders(): array
    {
        $lowStockProducts = Product::where('is_active', true)
            ->where('quantity', '<=', DB::raw('min_quantity'))
            ->with(['supplier', 'category'])
            ->get();

        if ($lowStockProducts->isEmpty()) {
            return [];
        }

        $suggestions = [];

        foreach ($lowStockProducts as $product) {
            if (!$product->supplier) {
                continue;
            }

            // Prédire la consommation avec IA
            $prediction = $this->predictConsumption($product->id, 7);

            // Utiliser l'IA pour analyser la quantité à commander
            $orderAnalysis = $this->getAIOrderAnalysis($product, $prediction);

            $suggestedQuantity = $orderAnalysis['suggested_quantity'] ?? 
                (max(0, $prediction['predicted_consumption'] - $product->quantity) + ($product->min_quantity * 2));

            if ($suggestedQuantity > 0) {
                $suggestion = AISuggestion::create([
                    'type' => 'order',
                    'title' => $orderAnalysis['title'] ?? "Commande suggérée: {$product->name}",
                    'description' => $orderAnalysis['description'] ?? "Stock bas ({$product->quantity} {$product->unit}). Consommation prévue: {$prediction['predicted_consumption']} {$product->unit} sur 7 jours.",
                    'data' => [
                        'product_id' => $product->id,
                        'supplier_id' => $product->supplier_id,
                        'current_stock' => $product->quantity,
                        'min_quantity' => $product->min_quantity,
                        'suggested_quantity' => round($suggestedQuantity, 3),
                        'predicted_consumption' => $prediction['predicted_consumption'],
                        'confidence' => $prediction['confidence'],
                        'ai_reasoning' => $orderAnalysis['reasoning'] ?? null,
                    ],
                    'product_id' => $product->id,
                    'confidence_score' => $prediction['confidence'],
                    'status' => 'pending',
                ]);

                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Utilise OpenAI pour analyser les commandes nécessaires
     */
    private function getAIOrderAnalysis(Product $product, array $prediction): array
    {
        try {
            $prompt = "Analyse la situation de stock suivante et suggère une commande optimale:\n\n";
            $prompt .= "Produit: {$product->name}\n";
            $prompt .= "Catégorie: {$product->category?->name}\n";
            $prompt .= "Stock actuel: {$product->quantity} {$product->unit}\n";
            $prompt .= "Stock minimum requis: {$product->min_quantity} {$product->unit}\n";
            $prompt .= "Consommation prévue (7 jours): {$prediction['predicted_consumption']} {$product->unit}\n";
            $prompt .= "Confiance de la prédiction: " . ($prediction['confidence'] * 100) . "%\n";
            if (isset($prediction['ai_analysis']['reasoning'])) {
                $prompt .= "Analyse IA: {$prediction['ai_analysis']['reasoning']}\n";
            }
            $prompt .= "\nSuggère la quantité optimale à commander en tenant compte:\n";
            $prompt .= "- La consommation prévue\n";
            $prompt .= "- Le stock de sécurité\n";
            $prompt .= "- Les délais de livraison (estimer 2-3 jours)\n";
            $prompt .= "- La saisonnalité\n";
            $prompt .= "\nRéponds en JSON: {\"suggested_quantity\": nombre, \"title\": \"titre\", \"description\": \"description détaillée\", \"reasoning\": \"raisonnement\"}";

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en gestion de stock et optimisation des commandes pour restaurants.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            return json_decode($content, true);

        } catch (\Exception $e) {
            Log::error('Erreur OpenAI pour analyse commande: ' . $e->getMessage());
            return [
                'suggested_quantity' => max(0, $prediction['predicted_consumption'] - $product->quantity) + ($product->min_quantity * 2),
                'title' => "Commande suggérée: {$product->name}",
                'description' => "Stock bas. Consommation prévue: {$prediction['predicted_consumption']} {$product->unit}",
            ];
        }
    }

    /**
     * Détecte les anomalies avec analyse IA contextuelle
     */
    public function detectAnomalies(): array
    {
        $anomalies = [];
        $products = Product::where('is_active', true)->get();

        foreach ($products as $product) {
            // Consommation des 7 derniers jours
            $recentConsumption = StockMovement::where('product_id', $product->id)
                ->whereIn('type', ['used', 'exit', 'wasted'])
                ->where('created_at', '>=', Carbon::now()->subDays(7))
                ->sum('quantity');

            // Consommation moyenne des 30 jours précédents
            $averageConsumption = StockMovement::where('product_id', $product->id)
                ->whereIn('type', ['used', 'exit', 'wasted'])
                ->where('created_at', '>=', Carbon::now()->subDays(37))
                ->where('created_at', '<', Carbon::now()->subDays(7))
                ->sum('quantity') / 30;

            if ($averageConsumption > 0) {
                $ratio = $recentConsumption / ($averageConsumption * 7);

                if ($ratio > 2.0 || $ratio < 0.3) {
                    // Utiliser l'IA pour analyser l'anomalie
                    $aiAnalysis = $this->getAIAnomalyAnalysis($product, $recentConsumption, $averageConsumption * 7, $ratio);

                    $anomalies[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'type' => $ratio > 2.0 ? 'high_consumption' : 'low_consumption',
                        'recent_consumption' => $recentConsumption,
                        'average_consumption' => $averageConsumption * 7,
                        'ratio' => round($ratio, 2),
                        'ai_analysis' => $aiAnalysis,
                        'severity' => $aiAnalysis['severity'] ?? ($ratio > 3.0 ? 'critical' : 'warning'),
                    ];
                }
            }
        }

        return $anomalies;
    }

    /**
     * Utilise OpenAI pour analyser les anomalies
     */
    private function getAIAnomalyAnalysis(Product $product, float $recent, float $average, float $ratio): array
    {
        try {
            $prompt = "Analyse cette anomalie de consommation dans un restaurant:\n\n";
            $prompt .= "Produit: {$product->name} ({$product->category?->name})\n";
            $prompt .= "Consommation récente (7 jours): {$recent} {$product->unit}\n";
            $prompt .= "Consommation moyenne (30 jours précédents): {$average} {$product->unit}\n";
            $prompt .= "Ratio: " . round($ratio, 2) . "x\n";
            $prompt .= "Type d'anomalie: " . ($ratio > 2.0 ? "Consommation anormalement élevée" : "Consommation anormalement faible") . "\n\n";
            $prompt .= "Analyse cette situation et suggère:\n";
            $prompt .= "1. Les causes possibles\n";
            $prompt .= "2. La sévérité (low, medium, high, critical)\n";
            $prompt .= "3. Des recommandations d'action\n";
            $prompt .= "\nRéponds en JSON: {\"severity\": \"low|medium|high|critical\", \"possible_causes\": [\"cause1\", \"cause2\"], \"recommendations\": [\"rec1\", \"rec2\"], \"reasoning\": \"explication\"}";

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en analyse de données et détection d\'anomalies pour restaurants.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.5,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            return json_decode($content, true);

        } catch (\Exception $e) {
            Log::error('Erreur OpenAI pour analyse anomalie: ' . $e->getMessage());
            return [
                'severity' => $ratio > 3.0 ? 'critical' : ($ratio > 2.0 ? 'high' : 'medium'),
                'possible_causes' => ['Données insuffisantes pour analyse détaillée'],
                'recommendations' => ['Vérifier manuellement la consommation'],
            ];
        }
    }

    /**
     * Génère des suggestions de réduction de gaspillage avec IA
     */
    public function getWasteReductionSuggestions(): array
    {
        $wastedProducts = StockMovement::where('type', 'wasted')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->with('product')
            ->get()
            ->groupBy('product_id');

        $suggestions = [];

        foreach ($wastedProducts as $productId => $movements) {
            $product = $movements->first()->product;
            $totalWasted = $movements->sum('quantity');
            $wastedValue = $totalWasted * ($product->purchase_price ?? 0);

            // Utiliser l'IA pour suggérer des solutions
            $aiSuggestions = $this->getAIWasteReductionSuggestions($product, $totalWasted, $wastedValue);

            foreach ($aiSuggestions as $aiSuggestion) {
                $suggestion = AISuggestion::create([
                    'type' => 'waste_reduction',
                    'title' => $aiSuggestion['title'],
                    'description' => $aiSuggestion['description'],
                    'data' => [
                        'product_id' => $product->id,
                        'total_wasted' => $totalWasted,
                        'wasted_value' => $wastedValue,
                        'suggestions' => $aiSuggestion['suggestions'] ?? [],
                    ],
                    'product_id' => $product->id,
                    'confidence_score' => $aiSuggestion['confidence'] ?? 0.7,
                    'status' => 'pending',
                ]);

                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Utilise OpenAI pour suggérer des solutions de réduction de gaspillage
     */
    private function getAIWasteReductionSuggestions(Product $product, float $totalWasted, float $wastedValue): array
    {
        try {
            $prompt = "Analyse ce cas de gaspillage alimentaire dans un restaurant et suggère des solutions concrètes:\n\n";
            $prompt .= "Produit: {$product->name} ({$product->category?->name})\n";
            $prompt .= "Quantité gaspillée (30 derniers jours): {$totalWasted} {$product->unit}\n";
            $prompt .= "Valeur du gaspillage: " . number_format($wastedValue, 2) . " €\n";
            $prompt .= "Stock actuel: {$product->quantity} {$product->unit}\n";
            $prompt .= "Stock minimum: {$product->min_quantity} {$product->unit}\n";
            $prompt .= "\nSuggère 3-5 solutions pratiques pour réduire ce gaspillage:\n";
            $prompt .= "- Solutions immédiates\n";
            $prompt .= "- Solutions à long terme\n";
            $prompt .= "- Transformations possibles\n";
            $prompt .= "- Optimisation des commandes\n";
            $prompt .= "\nRéponds en JSON: {\"suggestions\": [{\"title\": \"titre\", \"description\": \"description détaillée\", \"type\": \"immediate|long_term|transformation\", \"confidence\": 0.8}]}";

            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Tu es un expert en réduction du gaspillage alimentaire et optimisation des stocks pour restaurants.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            $aiData = json_decode($content, true);

            if (isset($aiData['suggestions']) && is_array($aiData['suggestions'])) {
                return array_map(function ($suggestion) use ($product, $totalWasted, $wastedValue) {
                    return [
                        'title' => $suggestion['title'] ?? "Réduire le gaspillage de {$product->name}",
                        'description' => $suggestion['description'] ?? "Suggestion pour réduire le gaspillage",
                        'suggestions' => [$suggestion],
                        'confidence' => $suggestion['confidence'] ?? 0.7,
                    ];
                }, $aiData['suggestions']);
            }

            return [];

        } catch (\Exception $e) {
            Log::error('Erreur OpenAI pour suggestions réduction gaspillage: ' . $e->getMessage());
            return [
                [
                    'title' => "Réduire le gaspillage de {$product->name}",
                    'description' => "{$totalWasted} {$product->unit} gaspillés récemment. Vérifiez les quantités commandées et les dates de péremption.",
                    'confidence' => 0.6,
                ]
            ];
        }
    }
}
