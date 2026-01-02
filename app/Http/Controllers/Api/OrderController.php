<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    protected $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        
        $user = $request->user();
        $query = Order::with(['supplier', 'user', 'items.product']);

        // Filtrer par établissement
        if ($user && $user->store_id) {
            $query->where('store_id', $user->store_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $this->authorize('create', Order::class);
        
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $user = $request->user();
            $order = Order::create([
                'supplier_id' => $validated['supplier_id'],
                'store_id' => $user->store_id, // Assigner automatiquement le store_id
                'user_id' => $user->id,
                'order_number' => $this->orderService->generateOrderNumber(),
                'status' => 'draft',
                'order_date' => now(),
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'total_amount' => 0,
            ]);

            $totalAmount = 0;

            foreach ($validated['items'] as $itemData) {
                $product = \App\Models\Product::find($itemData['product_id']);
                $totalPrice = $itemData['quantity'] * $itemData['unit_price'];

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'product_name' => $product->name,
                    'quantity' => $itemData['quantity'],
                    'unit' => $product->unit,
                    'unit_price' => $itemData['unit_price'],
                    'total_price' => $totalPrice,
                ]);

                $totalAmount += $totalPrice;
            }

            $order->update(['total_amount' => $totalAmount]);

            return response()->json($order->load(['supplier', 'items.product']), 201);
        });
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $order = Order::with(['supplier', 'user', 'items.product'])->findOrFail($id);

        // Vérifier que la commande appartient au même établissement
        if ($user && $user->store_id && $order->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($order);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $order = Order::findOrFail($id);

        // Vérifier que la commande appartient au même établissement
        if ($user && $user->store_id && $order->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $this->authorize('update', $order);
        
        $validated = $request->validate([
            'status' => 'sometimes|in:draft,pending,confirmed,delivered,cancelled',
            'expected_delivery_date' => 'nullable|date',
            'delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $order->update($validated);

        return response()->json($order->load(['supplier', 'items.product']));
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        try {
            $order = $this->orderService->generateOrderForSupplier(
                $validated['supplier_id'],
                $validated['product_ids'] ?? []
            );

            return response()->json($order, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function comparePrices(Request $request, string $productId)
    {
        $comparison = $this->orderService->compareSupplierPrices($productId);
        return response()->json($comparison);
    }
}
