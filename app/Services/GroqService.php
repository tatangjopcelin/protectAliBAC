<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    private $apiKey;
    private $baseUrl = 'https://api.groq.com/openai/v1';
    private $model;

    public function __construct()
    {
        $this->apiKey = config('services.groq.key');
        $this->model  = config('services.groq.model', 'llama-3.3-70b-versatile');
    }

    /**
     * Envoie une requête de chat à l'API GroqCloud (OpenAI-compatible).
     *
     * @param array $messages Messages au format OpenAI (role + content)
     * @param string $model Modèle (le code existant passe souvent 'command') ; ignoré si GROQ_MODEL est défini.
     * @param float $temperature Température (0.0 à 2.0)
     * @param array $options Options supplémentaires (support json_object via response_format)
     * @return object Réponse avec la même structure que CohereService : choices[0].message.content
     */
    public function chat(array $messages, string $model = 'command', float $temperature = 0.7, array $options = [])
    {
        try {
            if (!$this->apiKey) {
                throw new \Exception('GROQ_API_KEY n\'est pas configurée dans le fichier .env');
            }

            $requestedJsonObject = isset($options['response_format']['type']) && $options['response_format']['type'] === 'json_object';

            // Si JSON-only demandé, on force l'instruction sur le dernier message utilisateur.
            if ($requestedJsonObject && !empty($messages)) {
                $lastIndex = count($messages) - 1;
                $lastMessage = $messages[$lastIndex];
                $content = $lastMessage['content'] ?? '';
                $content = trim($content);
                $lastMessage['content'] = $content . "\n\nImportant: Réponds UNIQUEMENT en JSON valide, sans texte supplémentaire.";
                $messages[$lastIndex] = $lastMessage;
            }

            $payload = [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => $temperature,
                'max_tokens' => 1000,
            ];

            if ($requestedJsonObject) {
                // Tentative "officielle" si Groq supporte la paramétrisation OpenAI.
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/chat/completions", $payload);

            if ($response->successful()) {
                $data = $response->json();

                $content = $data['choices'][0]['message']['content'] ?? '';
                $content = trim($content);

                // Si jamais le modèle n'a pas respecté strictement json_object, on tente d'extraire le JSON.
                if ($requestedJsonObject) {
                    if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', $content, $matches)) {
                        $content = $matches[0];
                    }
                }

                return (object) [
                    'choices' => [
                        (object) [
                            'message' => (object) [
                                'content' => $content,
                            ],
                        ],
                    ],
                ];
            }

            $statusCode = $response->status();
            $errorBody = $response->body();
            $errorData = json_decode($errorBody, true);

            $errorMessage = is_array($errorData) && isset($errorData['error']['message'])
                ? $errorData['error']['message']
                : (is_string($errorBody) ? $errorBody : json_encode($errorBody));

            throw new \Exception("Groq API error (HTTP {$statusCode}): {$errorMessage}");
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Erreur de connexion à Groq API: ' . $e->getMessage());
            throw new \Exception('Impossible de se connecter à l\'API Groq. Vérifiez votre connexion internet.');
        } catch (\Exception $e) {
            Log::error('Erreur Groq API: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Vérifie si l'API Groq est configurée.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
}

