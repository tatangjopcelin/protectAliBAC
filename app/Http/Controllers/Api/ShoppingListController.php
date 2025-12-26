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
        $query = ShoppingListItem::with(['category', 'product', 'addedBy:id,name', 'orderedBy:id,name']);

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
    public function show(string $id)
    {
        $item = ShoppingListItem::with(['category', 'product', 'addedBy:id,name', 'orderedBy:id,name'])
            ->findOrFail($id);

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

        $item = ShoppingListItem::create([
            'name' => $validated['name'],
            'quantity' => $validated['quantity'],
            'unit' => $validated['unit'],
            'category_id' => $validated['category_id'] ?? null,
            'product_id' => $validated['product_id'] ?? null,
            'priority' => $validated['priority'] ?? 'medium',
            'status' => 'pending',
            'added_by' => $request->user()?->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json($item->load(['category', 'product', 'addedBy:id,name']), 201);
    }

    /**
     * Met à jour un item de la liste
     */
    public function update(Request $request, string $id)
    {
        $item = ShoppingListItem::findOrFail($id);

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
            $validated['ordered_by'] = $request->user()?->id;
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
        $item = ShoppingListItem::findOrFail($id);

        if ($item->status === 'received') {
            return response()->json([
                'message' => 'Cet item a déjà été reçu et ne peut plus être modifié'
            ], 422);
        }

        $item->update([
            'status' => 'ordered',
            'ordered_by' => $request->user()?->id,
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
        $item = ShoppingListItem::findOrFail($id);

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
        $item = ShoppingListItem::findOrFail($id);

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
    public function stats()
    {
        $total = ShoppingListItem::count();
        $pending = ShoppingListItem::where('status', 'pending')->count();
        $ordered = ShoppingListItem::where('status', 'ordered')->count();
        $received = ShoppingListItem::where('status', 'received')->count();
        $cancelled = ShoppingListItem::where('status', 'cancelled')->count();

        $byPriority = ShoppingListItem::selectRaw('priority, COUNT(*) as count')
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
