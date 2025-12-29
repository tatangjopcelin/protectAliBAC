<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GrokService
{
    private $apiKey;
    private $baseUrl;

    public function __construct()
    {
        $this->apiKey = env('GROK_API_KEY');
        $this->baseUrl = env('GROK_BASE_URL', 'https://api.x.ai/v1');
    }

    /**
     * Envoie une requête de chat à l'API Grok
     * 
     * @param array $messages Messages au format OpenAI (role + content)
     * @param string $model Modèle à utiliser (grok-beta, grok-2, etc.)
     * @param float $temperature Température (0.0 à 2.0)
     * @param array $options Options supplémentaires (response_format, etc.)
     * @return object Réponse au format compatible OpenAI
     */
    public function chat(array $messages, string $model = 'grok-beta', float $temperature = 0.7, array $options = [])
    {
        try {
            if (!$this->apiKey) {
                throw new \Exception('GROK_API_KEY n\'est pas configurée dans le fichier .env');
            }

            $payload = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
            ];

            // Ajouter les options supplémentaires si présentes
            if (!empty($options)) {
                $payload = array_merge($payload, $options);
            }

            $response = Http::timeout(30)->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/chat/completions", $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                // Vérifier que la réponse contient les données attendues
                if (!isset($data['choices']) || empty($data['choices'])) {
                    throw new \Exception('Réponse Grok invalide: pas de choix disponibles');
                }

                // Retourner un objet compatible avec OpenAI
                return (object) [
                    'choices' => [
                        (object) [
                            'message' => (object) [
                                'content' => $data['choices'][0]['message']['content'] ?? ''
                            ]
                        ]
                    ]
                ];
            }

            $errorBody = $response->body();
            $statusCode = $response->status();
            
            // Essayer de parser l'erreur JSON pour un message plus clair
            $errorData = json_decode($errorBody, true);
            $errorMessage = $errorData['error'] ?? $errorBody;
            
            throw new \Exception("Grok API error (HTTP {$statusCode}): {$errorMessage}");

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Erreur de connexion à Grok API: ' . $e->getMessage());
            throw new \Exception('Impossible de se connecter à l\'API Grok. Vérifiez votre connexion internet.');
        } catch (\Exception $e) {
            Log::error('Erreur Grok API: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Vérifie si l'API Grok est configurée et accessible
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}

