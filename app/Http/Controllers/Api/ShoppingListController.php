<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class ShoppingListController extends Controller
{
    /**
     * Liste tous les items de la liste d'achats
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = ShoppingListItem::with(['category', 'product', 'addedBy:id,name', 'orderedBy:id,name']);

        // Filtrer par établissement de l'utilisateur connecté (obligatoire)
        if ($user->store_id) {
            // Filtrer directement par store_id (plus performant maintenant que la colonne existe)
            $query->where('store_id', $user->store_id);
        } else {
            // Si l'utilisateur n'a pas de store_id, ne retourner aucun résultat pour la sécurité
            $query->whereRaw('1 = 0');
        }

        // Filtres
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Tri par défaut : priorité puis date de création
        $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
              ->orderBy('created_at', 'desc');

        $items = $query->get();

        return response()->json($items);
    }

    /**
     * Affiche un item spécifique
     */
    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $item = ShoppingListItem::with(['category', 'product', 'addedBy:id,name', 'orderedBy:id,name'])
            ->where('id', $id);

        // Filtrer par établissement de l'utilisateur connecté
        if ($user->store_id) {
            $item->where('store_id', $user->store_id);
        }

        $item = $item->firstOrFail();

        return response()->json($item);
    }

    /**
     * Ajoute un produit à la liste d'achats
     */
    public function store(Request $request)
    {
        // Tous les utilisateurs peuvent ajouter des produits à la liste
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'quantity' => 'required|numeric|min:0.001',
            'unit' => 'required|string|max:50',
            'category_id' => 'nullable|exists:categories,id',
            'product_id' => 'nullable|exists:products,id',
            'priority' => [
                'nullable',
                Rule::in(['low', 'medium', 'high', 'urgent']),
            ],
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $item = ShoppingListItem::create([
            'name' => $validated['name'],
            'quantity' => $validated['quantity'],
            'unit' => $validated['unit'],
            'category_id' => $validated['category_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'pending',
            'added_by' => $user->id,
            'store_id' => $user->store_id,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($item->load(['category', 'product', 'addedBy:id,name']), 201);
    }

    /**
     * Met à jour un item de la liste
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $item = ShoppingListItem::where('id', $id);
        
        // Filtrer par établissement de l'utilisateur connecté
        if ($user->store_id) {
            $item->where('store_id', $user->store_id);
        }
        
        $item = $item->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'quantity' => 'sometimes|numeric|min:0.001',
            'unit' => 'sometimes|string|max:50',
            'category_id' => 'nullable|exists:categories,id',
            'product_id' => 'nullable|exists:products,id',
            'priority' => [
                'sometimes',
                Rule::in(['low', 'medium', 'high', 'urgent']),
            ],
            'status' => [
                'sometimes',
                Rule::in(['pending', 'ordered', 'received', 'cancelled']),
            ],
            'notes' => 'nullable|string',
        ]);

        // Si le statut passe à "ordered", enregistrer qui a commandé et quand
        if (isset($validated['status']) && $validated['status'] === 'ordered' && $item->status !== 'ordered') {
            $validated['ordered_by'] = $user->id;
            $validated['ordered_at'] = Carbon::now();
        }

        // Si le statut passe à "received", enregistrer quand
        if (isset($validated['status']) && $validated['status'] === 'received' && $item->status !== 'received') {
            $validated['received_at'] = Carbon::now();
        }

        $item->update($validated);

        return response()->json($item->load(['category', 'product', 'addedBy:id,name', 'orderedBy:id,name']));
    }

    /**
     * Supprime un item de la liste
     */
    public function destroy(string $id)
    {
        $item = ShoppingListItem::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Item supprimé de la liste d\'achats'], 200);
    }

    /**
     * Marque un item comme commandé
     */
    public function markAsOrdered(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $item = ShoppingListItem::where('id', $id);
        
        // Filtrer par établissement de l'utilisateur connecté
        if ($user->store_id) {
            $item->where('store_id', $user->store_id);
        }
        
        $item = $item->firstOrFail();

        if ($item->status === 'received') {
            return response()->json([
                'message' => 'Cet item a déjà été reçu et ne peut plus être modifié'
            ], 422);
        }

        $item->update([
            'status' => 'ordered',
            'ordered_by' => $user->id,
            'ordered_at' => Carbon::now(),
        ]);

        return response()->json([
            'message' => 'Item marqué comme commandé',
            'item' => $item->load(['category', 'product', 'addedBy:id,name', 'orderedBy:id,name'])
        ]);
    }

    /**
     * Marque un item comme reçu
     */
    public function markAsReceived(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $item = ShoppingListItem::where('id', $id);
        
        // Filtrer par établissement de l'utilisateur connecté
        if ($user->store_id) {
            $item->where('store_id', $user->store_id);
        }
        
        $item = $item->firstOrFail();

        if ($item->status === 'pending') {
            return response()->json([
                'message' => 'Cet item doit d\'abord être marqué comme commandé'
            ], 422);
        }

        $item->update([
            'status' => 'received',
            'received_at' => Carbon::now(),
        ]);

        return response()->json([
            'message' => 'Item marqué comme reçu',
            'item' => $item->load(['category', 'product', 'addedBy:id,name', 'orderedBy:id,name'])
        ]);
    }

    /**
     * Annule un item
     */
    public function cancel(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $item = ShoppingListItem::where('id', $id);
        
        // Filtrer par établissement de l'utilisateur connecté
        if ($user->store_id) {
            $item->where('store_id', $user->store_id);
        }
        
        $item = $item->firstOrFail();

        if ($item->status === 'received') {
            return response()->json([
                'message' => 'Cet item a déjà été reçu et ne peut plus être annulé'
            ], 422);
        }

        $item->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'message' => 'Item annulé',
            'item' => $item->load(['category', 'product', 'addedBy:id,name', 'orderedBy:id,name'])
        ]);
    }

    /**
     * Statistiques de la liste d'achats
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = ShoppingListItem::query();
        
        // Filtrer par établissement de l'utilisateur connecté
        if ($user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        $total = (clone $query)->count();
        $pending = (clone $query)->where('status', 'pending')->count();
        $ordered = (clone $query)->where('status', 'ordered')->count();
        $received = (clone $query)->where('status', 'received')->count();
        $cancelled = (clone $query)->where('status', 'cancelled')->count();

        $byPriority = (clone $query)->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->get()
            ->pluck('count', 'priority');

        return response()->json([
            'total' => $total,
            'pending' => $pending,
            'ordered' => $ordered,
            'received' => $received,
            'cancelled' => $cancelled,
            'by_priority' => [
                'urgent' => $byPriority->get('urgent', 0),
                'high' => $byPriority->get('high', 0),
                'medium' => $byPriority->get('medium', 0),
                'low' => $byPriority->get('low', 0),
            ],
        ]);
    }
}
