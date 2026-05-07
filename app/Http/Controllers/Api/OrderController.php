<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SupplierOrderRequestMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $query = Order::with(['supplier', 'user', 'items.product']);

        // Filtrer par établissement
        $query->where('store_id', $user->store_id);

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
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit' => 'nullable|string|max:50',
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
                $product = !empty($itemData['product_id'])
                    ? \App\Models\Product::find($itemData['product_id'])
                    : null;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $itemData['product_id'] ?? null,
                    'product_name' => $itemData['product_name'],
                    'quantity' => $itemData['quantity'],
                    'unit' => $itemData['unit'] ?? ($product?->unit ?? 'u'),
                    'unit_price' => 0,
                    'total_price' => 0,
                ]);
            }
            $order->update(['total_amount' => 0]);

            return response()->json($order->load(['supplier', 'items.product']), 201);
        });
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $order = Order::with(['supplier', 'user', 'items.product'])->findOrFail($id);

        // Vérifier que la commande appartient au même établissement
        if ($order->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return response()->json($order);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $order = Order::findOrFail($id);

        // Vérifier que la commande appartient au même établissement
        if ($order->store_id !== $user->store_id) {
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
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        try {
            $order = $this->orderService->generateOrderForSupplier(
                $validated['supplier_id'],
                $validated['product_ids'] ?? [],
                $user
            );

            return response()->json($order, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function comparePrices(Request $request, string $productId)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $comparison = $this->orderService->compareSupplierPrices((int) $productId, (int) $user->store_id);
        return response()->json($comparison);
    }

    public function sendToSupplier(Request $request, string $id)
    {
        try {
            $user = $request->user();
            if (!$user || !$user->store_id) {
                return response()->json(['message' => 'Accès refusé'], 403);
            }
            $order = Order::with(['supplier', 'items', 'store'])->findOrFail($id);

            if ($order->store_id !== $user->store_id) {
                return response()->json(['message' => 'Accès refusé'], 403);
            }

            $this->authorize('update', $order);

            if (!$order->supplier || empty($order->supplier->email)) {
                return response()->json(['message' => 'Le fournisseur ne possède pas d\'email.'], 422);
            }

            $token = Str::random(80);
            $expiresAt = Carbon::now()->addDays(7);
            $order->update([
                'supplier_token' => $token,
                'supplier_token_expires_at' => $expiresAt,
                'status' => 'pending',
            ]);

            $apiBaseUrl = rtrim((string) config('app.url'), '/');
            $confirmUrl = $apiBaseUrl.'/api/supplier-orders/token/'.$token.'/respond/confirmed';
            $rejectUrl = $apiBaseUrl.'/api/supplier-orders/token/'.$token.'/respond/cancelled';

            Mail::to($order->supplier->email)->send(
                new SupplierOrderRequestMail($order->fresh(['supplier', 'items', 'store']), $confirmUrl, $rejectUrl)
            );

            return response()->json([
                'message' => 'Commande envoyée au fournisseur avec succès.',
                'order' => $order->fresh(['supplier', 'user', 'items.product']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Erreur envoi commande fournisseur', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Erreur lors de l\'envoi au fournisseur. Vérifiez la configuration mail et les migrations (orders.supplier_token*).',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function supplierShowByToken(string $token)
    {
        $order = Order::with(['supplier', 'store', 'items'])
            ->where('supplier_token', $token)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Lien invalide'], 404);
        }

        if (!$order->supplier_token_expires_at || $order->supplier_token_expires_at->isPast()) {
            return response()->json(['message' => 'Lien expiré'], 410);
        }

        return response()->json($order);
    }

    public function supplierRespondByToken(Request $request, string $token, string $decision)
    {
        if (!in_array($decision, ['confirmed', 'cancelled'], true)) {
            return response()->json(['message' => 'Décision invalide'], 422);
        }

        $order = Order::where('supplier_token', $token)->first();
        if (!$order) {
            return response()->json(['message' => 'Lien invalide'], 404);
        }

        if (!$order->supplier_token_expires_at || $order->supplier_token_expires_at->isPast()) {
            return response()->json(['message' => 'Lien expiré'], 410);
        }

        if (in_array($order->status, ['delivered'], true)) {
            return response()->json(['message' => 'Commande déjà livrée, impossible de modifier la réponse fournisseur.'], 422);
        }

        $note = trim((string) ($request->input('note', $request->query('note', ''))));

        if ($decision === 'cancelled' && $note === '') {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Le motif de refus est obligatoire.',
                ], 422);
            }

            return response()->view('supplier-orders.reject-reason', [
                'token' => $token,
            ], 422);
        }

        $order->update([
            'status' => $decision,
            'supplier_responded_at' => now(),
            'supplier_response_note' => $note,
        ]);

        return response()->json([
            'message' => $decision === 'confirmed'
                ? 'Commande confirmée par le fournisseur.'
                : 'Commande refusée par le fournisseur.',
            'order' => $order->fresh(['supplier', 'store', 'items']),
        ]);
    }
}
