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

    /**
     * Test de connexion à l'API GroqCloud
     */
    public function testConnection(Request $request)
    {
        try {
            $aiService = app(\App\Services\GroqService::class);
            
            if (!$aiService->isConfigured()) {
                return response()->json([
                    'success' => false,
                    'message' => 'GROQ_API_KEY n\'est pas configurée dans le fichier .env',
                    'configured' => false,
                    'hint' => 'Ajoutez GROQ_API_KEY=votre-cle dans le fichier .env. Obtenez une clé gratuite sur https://groq.com/'
                ], 400);
            }

            // Test simple avec une question basique
            $testPrompt = "Réponds simplement 'OK' si tu reçois ce message.";
            
            $response = $aiService->chat([
                [
                    'role' => 'user',
                    'content' => $testPrompt
                ]
            ], 'command', 0.7);

            $content = $response->choices[0]->message->content ?? '';

            return response()->json([
                'success' => true,
                'message' => 'Connexion à Groq réussie !',
                'configured' => true,
                'test_response' => $content,
                'model' => 'command',
                'timestamp' => now()->toDateTimeString()
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur test connexion Groq: ' . $e->getMessage());
            
            $errorMessage = $e->getMessage();
            $hint = 'Vérifiez votre clé API Groq dans le fichier .env';
            
            // Détecter les erreurs spécifiques
            if (str_contains($errorMessage, 'API key') || str_contains($errorMessage, '401') || str_contains($errorMessage, '403')) {
                $hint = 'Votre clé API Groq est invalide. Obtenez une nouvelle clé gratuite sur https://groq.com/';
            } elseif (str_contains($errorMessage, 'Connection')) {
                $hint = 'Problème de connexion. Vérifiez votre connexion internet.';
            } elseif (str_contains($errorMessage, 'rate limit') || str_contains($errorMessage, '429')) {
                $hint = 'Limite de requêtes atteinte. Attendez quelques instants.';
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion à Groq',
                'error' => $errorMessage,
                'configured' => $aiService->isConfigured() ?? false,
                'hint' => $hint,
                'help_url' => 'https://groq.com/'
            ], 500);
        }
    }
}
