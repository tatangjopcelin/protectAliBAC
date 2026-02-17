<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        \Log::info('StoreController::update appelé', [
            'id' => $id,
            'path' => $request->path(),
            'url' => $request->url(),
        ]);
        // Cette méthode ne devrait pas être appelée pour clock-in-verification-method
        return response()->json(['message' => 'Méthode non implémentée'], 501);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * Mettre à jour la méthode de vérification pour le pointage (admin uniquement)
     */
    public function updateClockInVerificationMethod(Request $request)
    {
        \Log::info('updateClockInVerificationMethod appelé', [
            'user_id' => $request->user()->id ?? null,
            'request_data' => $request->all()
        ]);
        
        try {
            $user = $request->user();
            
            // Seul l'admin peut modifier cette méthode
            if ($user->role !== 'admin') {
                \Log::warning('Tentative de modification par non-admin', ['user_id' => $user->id, 'role' => $user->role]);
                return response()->json(['message' => 'Accès refusé. Seul l\'administrateur peut modifier cette méthode.'], 403);
            }

            $validated = $request->validate([
                'clock_in_verification_method' => 'required|in:code,photo',
            ]);

            $store = $user->store;
            if (!$store) {
                \Log::warning('Store non trouvé pour l\'utilisateur', ['user_id' => $user->id]);
                return response()->json(['message' => 'Aucun établissement associé.'], 404);
            }

            \Log::info('Mise à jour de la méthode', [
                'store_id' => $store->id,
                'ancienne_methode' => $store->clock_in_verification_method,
                'nouvelle_methode' => $validated['clock_in_verification_method']
            ]);

            $store->clock_in_verification_method = $validated['clock_in_verification_method'];
            $saved = $store->save();
            
            if (!$saved) {
                \Log::error('Échec de la sauvegarde', ['store_id' => $store->id]);
                return response()->json(['message' => 'Erreur lors de la sauvegarde.'], 500);
            }
            
            // Recharger le store depuis la base pour s'assurer que la valeur est bien persistée
            $store->refresh();
            
            \Log::info('Méthode de vérification mise à jour avec succès', [
                'store_id' => $store->id,
                'method' => $store->clock_in_verification_method,
            ]);

            $response = [
                'message' => 'Méthode de vérification mise à jour avec succès',
                'clock_in_verification_method' => $store->clock_in_verification_method,
            ];
            
            // Vérifier la valeur en base de données directement pour confirmer la sauvegarde
            $dbValue = \DB::table('stores')
                ->where('id', $store->id)
                ->value('clock_in_verification_method');
            \Log::info('Valeur en base de données après sauvegarde', [
                'store_id' => $store->id,
                'db_value' => $dbValue,
                'model_value' => $store->clock_in_verification_method
            ]);
            
            \Log::info('Réponse JSON préparée', ['response' => $response]);
            
            // Retourner la réponse JSON de manière standard
            return response()->json($response, 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la mise à jour de la méthode de vérification: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id ?? null,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'message' => 'Erreur lors de la mise à jour',
                'error' => config('app.debug') ? $e->getMessage() : 'Une erreur est survenue',
            ], 500);
        }
    }
}
