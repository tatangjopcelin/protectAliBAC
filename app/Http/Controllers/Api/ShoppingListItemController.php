<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Notifications\ShoppingListItemCreatedNotification;
use App\Notifications\ShoppingListItemUpdatedNotification;
use Illuminate\Http\Request;

class ShoppingListItemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = ShoppingListItem::with(['addedBy', 'category', 'product']);

        // Filtrer par établissement de l'utilisateur connecté (obligatoire)
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucun résultat pour la sécurité
            $query->whereRaw('1 = 0');
        }

        // Filtrer par statut si spécifié
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filtrer par priorité si spécifié
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Trier par priorité et date de création
        $items = $query->orderByRaw("CASE priority 
            WHEN 'urgent' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'medium' THEN 3 
            WHEN 'low' THEN 4 
            END")
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($items);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Vérifier les permissions avec la Policy
        $policy = new \App\Policies\ShoppingListItemPolicy();
        if (!$policy->create($user)) {
            return response()->json([
                'message' => 'Accès refusé',
                'error' => 'Vous n\'avez pas la permission de créer un item de liste de courses'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'quantity' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:50',
            'category_id' => 'nullable|exists:categories,id',
            'product_id' => 'nullable|exists:products,id',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'notes' => 'nullable|string',
        ]);

        $item = ShoppingListItem::create([
            'name' => $validated['name'],
            'quantity' => $validated['quantity'] ?? 1,
            'unit' => $validated['unit'] ?? 'unité',
            'category_id' => $validated['category_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'pending',
            'added_by' => $user->id,
            'store_id' => $user->store_id,
            'notes' => $validated['notes'] ?? null,
        ]);

        $item->load(['addedBy', 'category', 'product']);

        // Envoyer une notification par email à tous les autres utilisateurs
        $this->notifyAllUsersExcept($user, new ShoppingListItemCreatedNotification($item, $user));

        return response()->json([
            'message' => 'Produit ajouté à la liste de courses',
            'item' => $item
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $item = ShoppingListItem::with(['addedBy', 'category', 'product']);
        
        // Filtrer par établissement de l'utilisateur connecté
        if ($user->store_id) {
            $item->where('store_id', $user->store_id);
        } else {
            $item->whereRaw('1 = 0');
        }
        
        $item = $item->findOrFail($id);
        return response()->json($item);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['message' => 'Non authentifié'], 401);
            }

            // Filtrer par établissement de l'utilisateur connecté
            $item = ShoppingListItem::query();
            if ($user->store_id) {
                $item->where('store_id', $user->store_id);
            } else {
                $item->whereRaw('1 = 0');
            }
            $item = $item->findOrFail($id);
            
            // Vérifier les permissions avec la Policy
            $policy = new \App\Policies\ShoppingListItemPolicy();
            if (!$policy->update($user, $item)) {
                return response()->json([
                    'message' => 'Accès refusé',
                    'error' => 'Vous n\'avez pas la permission de modifier cet item'
                ], 403);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'quantity' => 'nullable|numeric|min:0',
                'unit' => 'nullable|string|max:50',
                'category_id' => 'nullable|exists:categories,id',
                'product_id' => 'nullable|exists:products,id',
                'priority' => 'nullable|in:low,medium,high,urgent',
                'status' => 'nullable|in:pending,ordered,received,cancelled',
                'notes' => 'nullable|string',
            ]);

            // Filtrer les valeurs null pour éviter les erreurs, mais garder les valeurs 0
            $updateData = array_filter($validated, function($value) {
                return $value !== null && $value !== '';
            }, ARRAY_FILTER_USE_BOTH);

            $item->update($updateData);
            $item->refresh();
            $item->load(['addedBy', 'category', 'product']);

            // Envoyer une notification par email à tous les autres utilisateurs
            $this->notifyAllUsersExcept($user, new ShoppingListItemUpdatedNotification($item, $user));

            return response()->json([
                'message' => 'Item mis à jour avec succès',
                'item' => $item
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la mise à jour de l\'item de liste de courses: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'item_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'message' => 'Erreur lors de la mise à jour',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = request()->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Filtrer par établissement de l'utilisateur connecté
        $item = ShoppingListItem::query();
        if ($user->store_id) {
            $item->where('store_id', $user->store_id);
        } else {
            $item->whereRaw('1 = 0');
        }
        $item = $item->findOrFail($id);
        
        // Vérifier les permissions avec la Policy
        $policy = new \App\Policies\ShoppingListItemPolicy();
        if (!$policy->delete($user, $item)) {
            return response()->json([
                'message' => 'Accès refusé',
                'error' => 'Vous n\'avez pas la permission de supprimer cet item'
            ], 403);
        }

        $item->delete();

        return response()->json([
            'message' => 'Item supprimé de la liste de courses'
        ]);
    }

    /**
     * Marquer un item comme acheté
     */
    public function markAsPurchased(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Filtrer par établissement de l'utilisateur connecté
        $item = ShoppingListItem::query();
        if ($user->store_id) {
            $item->where('store_id', $user->store_id);
        } else {
            $item->whereRaw('1 = 0');
        }
        $item = $item->findOrFail($id);
        
        // Vérifier les permissions avec la Policy
        $policy = new \App\Policies\ShoppingListItemPolicy();
        if (!$policy->update($user, $item)) {
            return response()->json([
                'message' => 'Accès refusé',
                'error' => 'Vous n\'avez pas la permission de modifier cet item'
            ], 403);
        }

        $item->update([
            'status' => 'received',
            'received_at' => now(),
        ]);

        $item->load(['addedBy', 'category', 'product']);

        return response()->json([
            'message' => 'Item marqué comme acheté',
            'item' => $item
        ]);
    }

    /**
     * Notifier tous les utilisateurs sauf celui qui a fait l'action.
     * Envoi différé après la réponse HTTP pour ne pas bloquer le client.
     */
    private function notifyAllUsersExcept(User $excludedUser, $notification)
    {
        try {
            $query = User::where('id', '!=', $excludedUser->id)
                ->whereNotNull('email_verified_at');

            if ($excludedUser->store_id) {
                $query->where('store_id', $excludedUser->store_id);
            }

            $users = $query->get();

            if ($users->isEmpty()) {
                return;
            }

            dispatch(function () use ($users, $notification) {
                foreach ($users as $user) {
                    try {
                        $user->notify(clone $notification);
                    } catch (\Exception $e) {
                        \Log::error('Erreur envoi notification liste courses', [
                            'user_id'    => $user->id,
                            'user_email' => $user->email,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            })->afterResponse();

            \Log::info('Notifications liste courses planifiées (afterResponse)', [
                'excluded_user_id'     => $excludedUser->id,
                'store_id'             => $excludedUser->store_id,
                'notified_users_count' => $users->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur notifyAllUsersExcept (ShoppingList): ' . $e->getMessage());
        }
    }
}
