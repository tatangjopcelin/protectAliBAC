<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AISuggestion;
use App\Models\AiConversation;
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
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json([
                'suggestions' => [],
                'count' => 0,
            ]);
        }
        $days = $request->get('days', 3);
        $suggestions = $this->aiService->suggestRecipesForExpiringProducts($days, (int) $user->store_id);

        return response()->json([
            'suggestions' => $suggestions,
            'count' => count($suggestions),
        ]);
    }

    public function predictConsumption(Request $request, string $productId)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json([
                'error' => 'Utilisateur ou store_id introuvable',
            ], 403);
        }
        $days = $request->get('days', 7);
        $prediction = $this->aiService->predictConsumption((int) $productId, (int) $days, (int) $user->store_id);

        return response()->json($prediction);
    }

    public function suggestOrders()
    {
        $user = request()->user();
        if (!$user || !$user->store_id) {
            return response()->json([
                'suggestions' => [],
                'count' => 0,
            ]);
        }
        $suggestions = $this->aiService->suggestOrders((int) $user->store_id);

        return response()->json([
            'suggestions' => $suggestions,
            'count' => count($suggestions),
        ]);
    }

    public function detectAnomalies()
    {
        $user = request()->user();
        if (!$user || !$user->store_id) {
            return response()->json([
                'anomalies' => [],
                'count' => 0,
            ]);
        }
        $anomalies = $this->aiService->detectAnomalies((int) $user->store_id);

        return response()->json([
            'anomalies' => $anomalies,
            'count' => count($anomalies),
        ]);
    }

    public function wasteReductionSuggestions()
    {
        $user = request()->user();
        if (!$user || !$user->store_id) {
            return response()->json([
                'suggestions' => [],
                'count' => 0,
            ]);
        }
        $suggestions = $this->aiService->getWasteReductionSuggestions((int) $user->store_id);

        return response()->json([
            'suggestions' => $suggestions,
            'count' => count($suggestions),
        ]);
    }

    /**
     * Retourne la dernière conversation IA de l'utilisateur connecté
     */
    public function latestChatConversation(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'conversation_id' => null,
                'messages' => [],
            ], 401);
        }

        $conversation = AiConversation::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$conversation) {
            return response()->json([
                'conversation_id' => null,
                'messages' => [],
            ]);
        }

        return response()->json([
            'conversation_id' => (int) $conversation->id,
            'messages' => $conversation->messages ?? [],
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = AISuggestion::with(['product', 'recipe', 'user']);

        // Filtrage par établissement : ne voir que les suggestions liées à des produits de son store
        if ($user && $user->store_id) {
            $query->whereHas('product.zone', function ($q) use ($user) {
                $q->where('store_id', $user->store_id);
            });
        } else {
            // Si pas de store_id, on ne renvoie rien pour éviter les fuites
            return response()->json([]);
        }

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
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $suggestion = AISuggestion::whereHas('product.zone', function ($q) use ($user) {
            $q->where('store_id', $user->store_id);
        })->findOrFail($id);
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

    /**
     * Chat direct avec l'IA (conversation libre)
     */
    public function chat(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        $validated = $request->validate([
            'conversation_id' => 'nullable|integer|min:1|exists:ai_conversations,id',
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:4000',
        ]);

        try {
            $aiService = app(\App\Services\GroqService::class);
            if (!$aiService->isConfigured()) {
                return response()->json([
                    'error' => 'GROQ_API_KEY n\'est pas configurée dans le fichier .env',
                ], 400);
            }

            // Créer (ou récupérer) la conversation côté BD
            $conversation = null;
            if (!empty($validated['conversation_id'])) {
                // Vérifier que la conversation appartient au user connecté
                $conversation = AiConversation::where('id', (int) $validated['conversation_id'])
                    ->where('user_id', $user->id)
                    ->first();
            }
            if (!$conversation) {
                $conversation = AiConversation::create([
                    'user_id' => $user->id,
                    'messages' => $validated['messages'],
                ]);
            }

            $storeName = $user->store?->name ?? 'N/A';
            $userName = $user->name ?? 'N/A';
            $userRole = $user->role ?? 'N/A';

            $systemPrompt = "Tu es un assistant IA utile et précis pour gérer un restaurant/boucherie.\n"
                . "Réponds en FRANCAIS.\n"
                . "Le contexte utilisateur: nom={$userName}, role={$userRole}, store={$storeName}.\n"
                . "Si l'utilisateur demande des recommandations opérationnelles, propose des étapes concrètes.\n"
                . "Si l'utilisateur manque d'informations, pose une ou deux questions de clarification avant de conclure.\n";

            $messages = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $validated['messages']
            );

            $response = $aiService->chat($messages, 'command', 0.5);
            $content = $response->choices[0]->message->content ?? '';
            $content = trim($content);

            // Enregistrer l'assistant dans la conversation BD
            $convMessages = $validated['messages'];
            $last = $convMessages[count($convMessages) - 1] ?? null;
            if (!$last || ($last['role'] ?? null) !== 'assistant') {
                $convMessages[] = ['role' => 'assistant', 'content' => $content];
            }
            $conversation->messages = $convMessages;
            $conversation->save();

            return response()->json([
                'message' => $content,
                'conversation_id' => $conversation->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur chat Groq: ' . $e->getMessage());
            return response()->json([
                'error' => 'Erreur lors du traitement du chat',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
