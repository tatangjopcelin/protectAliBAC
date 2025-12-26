<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AISuggestion;
use App\Services\AIService;
use Illuminate\Http\Request;

class AIController extends Controller
{
    protected $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function suggestRecipes(Request $request)
    {
        $days = $request->get('days', 3);
        $suggestions = $this->aiService->suggestRecipesForExpiringProducts($days);

        return response()->json([
            'suggestions' => $suggestions,
            'count' => count($suggestions),
        ]);
    }

    public function predictConsumption(Request $request, string $productId)
    {
        $days = $request->get('days', 7);
        $prediction = $this->aiService->predictConsumption($productId, $days);

        return response()->json($prediction);
    }

    public function suggestOrders()
    {
        $suggestions = $this->aiService->suggestOrders();

        return response()->json([
            'suggestions' => $suggestions,
            'count' => count($suggestions),
        ]);
    }

    public function detectAnomalies()
    {
        $anomalies = $this->aiService->detectAnomalies();

        return response()->json([
            'anomalies' => $anomalies,
            'count' => count($anomalies),
        ]);
    }

    public function wasteReductionSuggestions()
    {
        $suggestions = $this->aiService->getWasteReductionSuggestions();

        return response()->json([
            'suggestions' => $suggestions,
            'count' => count($suggestions),
        ]);
    }

    public function index(Request $request)
    {
        $query = AISuggestion::with(['product', 'recipe', 'user']);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function updateStatus(Request $request, string $id)
    {
        $suggestion = AISuggestion::findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:pending,accepted,rejected,dismissed',
        ]);

        $suggestion->update($validated);

        if ($validated['status'] === 'accepted' && $suggestion->type === 'order') {
            // TODO: Créer automatiquement la commande
        }

        return response()->json($suggestion);
    }
}
