<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HuggingFaceService
{
    private $apiKey;
    // Utiliser l'API Inference Endpoints directement (plus stable)
    private $baseUrl = 'https://api-inference.huggingface.co';

    public function __construct()
    {
        $this->apiKey = env('HUGGINGFACE_API_KEY');
    }

    /**
     * Envoie une requête de chat à l'API Hugging Face
     * 
     * @param array $messages Messages au format OpenAI (role + content)
     * @param string $model Modèle à utiliser (meta-llama/Llama-3.1-8B-Instruct, etc.)
     * @param float $temperature Température (0.0 à 2.0)
     * @param array $options Options supplémentaires (response_format, etc.)
     * @return object Réponse au format compatible OpenAI
     */
    public function chat(array $messages, string $model = 'meta-llama/Llama-3.1-8B-Instruct', float $temperature = 0.7, array $options = [])
    {
        try {
            if (!$this->apiKey) {
                throw new \Exception('HUGGINGFACE_API_KEY n\'est pas configurée dans le fichier .env');
            }

            // Convertir les messages en format prompt pour Hugging Face
            $prompt = $this->formatMessagesToPrompt($messages);

            $payload = [
                'inputs' => $prompt,
                'parameters' => [
                    'temperature' => $temperature,
                    'max_new_tokens' => 800,
                    'return_full_text' => false,
                    'top_p' => 0.9,
                ]
            ];

            // Si response_format est demandé, on l'ajoute dans les instructions
            if (isset($options['response_format']) && $options['response_format']['type'] === 'json_object') {
                $prompt .= "\n\nImportant: Réponds UNIQUEMENT en JSON valide, sans texte supplémentaire.";
            }

            // Utiliser l'API Inference Endpoints
            // Format: https://api-inference.huggingface.co/models/{model}
            // Note: Si vous obtenez une erreur 410, utilisez un Inference Endpoint dédié
            $url = "{$this->baseUrl}/models/{$model}";
            
            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                // Hugging Face peut retourner différents formats
                $content = '';
                
                if (is_array($data)) {
                    // Format standard : [{"generated_text": "..."}]
                    if (isset($data[0]['generated_text'])) {
                        $content = $data[0]['generated_text'];
                    }
                    // Format alternatif
                    elseif (isset($data[0]) && is_string($data[0])) {
                        $content = $data[0];
                    }
                    // Format avec error (modèle en chargement)
                    elseif (isset($data['error'])) {
                        throw new \Exception("Hugging Face API error: " . $data['error']);
                    }
                    else {
                        $content = json_encode($data);
                    }
                } elseif (is_string($data)) {
                    $content = $data;
                } else {
                    $content = json_encode($data);
                }

                // Nettoyer la réponse (retirer le prompt si présent)
                $content = str_replace($prompt, '', $content);
                $content = trim($content);
                
                // Si response_format JSON était demandé, essayer d'extraire le JSON
                if (isset($options['response_format']) && $options['response_format']['type'] === 'json_object') {
                    // Essayer d'extraire le JSON de la réponse
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

            // Gérer les erreurs spécifiques de Hugging Face
            $statusCode = $response->status();
            $errorBody = $response->body();
            $errorData = json_decode($errorBody, true);
            
            // Gérer l'erreur 410 (endpoint obsolète) - utiliser Inference Endpoints
            if ($statusCode === 410) {
                throw new \Exception("L'endpoint public est obsolète. Pour une utilisation en production, créez un Inference Endpoint dédié sur https://huggingface.co/inference-endpoints ou utilisez un modèle via l'API Inference Endpoints.");
            }
            
            if ($statusCode === 503 && isset($errorData['error'])) {
                // Modèle en cours de chargement
                throw new \Exception("Le modèle est en cours de chargement. Veuillez réessayer dans quelques secondes.");
            }

            $errorMessage = is_array($errorData) && isset($errorData['error']) 
                ? $errorData['error'] 
                : (is_string($errorBody) ? $errorBody : json_encode($errorBody));
            
            throw new \Exception("Hugging Face API error (HTTP {$statusCode}): {$errorMessage}");

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Erreur de connexion à Hugging Face API: ' . $e->getMessage());
            throw new \Exception('Impossible de se connecter à l\'API Hugging Face. Vérifiez votre connexion internet.');
        } catch (\Exception $e) {
            Log::error('Erreur Hugging Face API: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Convertit les messages au format prompt pour Hugging Face
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
        $prompt .= "Assistant:";
        return $prompt;
    }

    /**
     * Vérifie si l'API Hugging Face est configurée
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}

