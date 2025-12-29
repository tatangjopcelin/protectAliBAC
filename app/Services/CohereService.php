<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CohereService
{
    private $apiKey;
    private $baseUrl = 'https://api.cohere.ai/v1';

    public function __construct()
    {
        $this->apiKey = env('COHERE_API_KEY');
    }

    /**
     * Envoie une requête de chat à l'API Cohere
     * 
     * @param array $messages Messages au format OpenAI (role + content)
     * @param string $model Modèle à utiliser (command, command-light, etc.)
     * @param float $temperature Température (0.0 à 2.0)
     * @param array $options Options supplémentaires
     * @return object Réponse au format compatible OpenAI
     */
    public function chat(array $messages, string $model = 'command', float $temperature = 0.7, array $options = [])
    {
        try {
            if (!$this->apiKey) {
                throw new \Exception('COHERE_API_KEY n\'est pas configurée dans le fichier .env');
            }

            // Utiliser la nouvelle API Chat de Cohere
            // Convertir les messages au format Chat API
            $chatMessages = [];
            foreach ($messages as $message) {
                $role = $message['role'] ?? 'user';
                $content = $message['content'] ?? '';
                
                // Cohere utilise 'USER' et 'CHATBOT' au lieu de 'user' et 'assistant'
                if ($role === 'user') {
                    $chatMessages[] = ['role' => 'USER', 'message' => $content];
                } elseif ($role === 'assistant') {
                    $chatMessages[] = ['role' => 'CHATBOT', 'message' => $content];
                } elseif ($role === 'system') {
                    // Les messages système sont ajoutés comme premier message USER avec instruction
                    $chatMessages[] = ['role' => 'USER', 'message' => $content];
                }
            }

            $payload = [
                'model' => $model,
                'messages' => $chatMessages,
                'temperature' => $temperature,
                'max_tokens' => 1000,
                'p' => 0.9,
            ];

            // Si response_format JSON est demandé, ajouter l'instruction dans le dernier message
            if (isset($options['response_format']) && $options['response_format']['type'] === 'json_object') {
                if (!empty($chatMessages)) {
                    $lastMessage = &$chatMessages[count($chatMessages) - 1];
                    $lastMessage['message'] .= "\n\nImportant: Réponds UNIQUEMENT en JSON valide, sans texte supplémentaire.";
                }
                $payload['messages'] = $chatMessages;
            }

            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/chat", $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                // La nouvelle API Chat retourne le texte dans 'text' au lieu de 'generations'
                $content = $data['text'] ?? '';
                $content = trim($content);
                
                // Si response_format JSON était demandé, essayer d'extraire le JSON
                if (isset($options['response_format']) && $options['response_format']['type'] === 'json_object') {
                    if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $content, $matches)) {
                        $content = $matches[0];
                    }
                }

                return (object) [
                    'choices' => [
                        (object) [
                            'message' => (object) [
                                'content' => $content
                            ]
                        ]
                    ]
                ];
            }

            $statusCode = $response->status();
            $errorBody = $response->body();
            $errorData = json_decode($errorBody, true);
            
            $errorMessage = is_array($errorData) && isset($errorData['message']) 
                ? $errorData['message'] 
                : (is_string($errorBody) ? $errorBody : json_encode($errorBody));
            
            throw new \Exception("Cohere API error (HTTP {$statusCode}): {$errorMessage}");

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Erreur de connexion à Cohere API: ' . $e->getMessage());
            throw new \Exception('Impossible de se connecter à l\'API Cohere. Vérifiez votre connexion internet.');
        } catch (\Exception $e) {
            Log::error('Erreur Cohere API: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Convertit les messages au format prompt pour Cohere (utilisé pour les instructions JSON)
     * Note: La nouvelle API Chat utilise directement les messages, cette méthode est pour compatibilité
     */
    private function formatMessagesToPrompt(array $messages): string
    {
        $prompt = '';
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';
            
            if ($role === 'system') {
                $prompt .= "System: {$content}\n\n";
            } elseif ($role === 'user') {
                $prompt .= "User: {$content}\n\n";
            } elseif ($role === 'assistant') {
                $prompt .= "Assistant: {$content}\n\n";
            }
        }
        return trim($prompt);
    }

    /**
     * Vérifie si l'API Cohere est configurée
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}

