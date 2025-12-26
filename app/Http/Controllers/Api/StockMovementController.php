<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $query = StockMovement::with(['product', 'user', 'fromZone', 'toZone', 'recipe']);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'type' => 'required|in:entry,exit,transfer,used,wasted,transformed',
            'quantity' => 'required|numeric|min:0.001',
            'from_zone_id' => 'nullable|exists:zones,id',
            'to_zone_id' => 'nullable|exists:zones,id',
            'notes' => 'nullable|string',
            'recipe_id' => 'nullable|exists:recipes,id',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $product = Product::findOrFail($validated['product_id']);
            
            // Mettre à jour la quantité du produit
            if (in_array($validated['type'], ['entry', 'transfer'])) {
                $product->quantity += $validated['quantity'];
            } elseif (in_array($validated['type'], ['exit', 'used', 'wasted', 'transformed'])) {
                if ($product->quantity < $validated['quantity']) {
                    return response()->json(['error' => 'Stock insuffisant'], 400);
                }
                $product->quantity -= $validated['quantity'];
            }

            // Gérer les transferts de zone
            if ($validated['type'] === 'transfer') {
                if ($validated['from_zone_id']) {
                    $product->zone_id = $validated['to_zone_id'];
                }
            }

            $product->save();

            // Créer le mouvement de stock
            $validated['user_id'] = $request->user()?->id;
            $movement = StockMovement::create($validated);

            return response()->json($movement->load(['product', 'user', 'fromZone', 'toZone']), 201);
        });
    }

    public function show(string $id)
    {
        return response()->json(
            StockMovement::with(['product', 'user', 'fromZone', 'toZone', 'recipe'])->findOrFail($id)
        );
    }
}
