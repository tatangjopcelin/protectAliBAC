<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\SupplierOrderRequestMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Services\NotificationService;
use App\Services\OrderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    protected $orderService;

    public function __construct(
        OrderService $orderService,
        protected NotificationService $notificationService
    ) {
        $this->orderService = $orderService;
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);
        
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $query = Order::with(['supplier', 'user', 'items.product', 'deliveryReceivedBy']);

        // Filtrer par établissement
        $query->where('store_id', $user->store_id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        $orders = $query->orderBy('created_at', 'desc')->get();
        $contactsPayload = optional(Store::find($user->store_id))->supplierOrderContactsPayload()
            ?? ['store' => null, 'staff' => []];
        $orders->each(fn (Order $o) => $o->setAttribute('establishment_contacts', $contactsPayload));

        return response()->json($orders);
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

            $order->load(['supplier', 'items.product', 'store', 'deliveryReceivedBy']);
            $order->setAttribute(
                'establishment_contacts',
                optional($order->store)->supplierOrderContactsPayload() ?? ['store' => null, 'staff' => []]
            );

            return response()->json($order, 201);
        });
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $order = Order::with(['supplier', 'user', 'items.product', 'store', 'deliveryReceivedBy'])->findOrFail($id);

        // Vérifier que la commande appartient au même établissement
        if ($order->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $order->setAttribute(
            'establishment_contacts',
            optional($order->store)->supplierOrderContactsPayload() ?? ['store' => null, 'staff' => []]
        );

        return response()->json($order);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $order = Order::with(['items'])->findOrFail($id);

        // Vérifier que la commande appartient au même établissement
        if ($order->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $this->authorize('update', $order);

        $isFullRevision = $request->has('items') && is_array($request->input('items'));

        if ($isFullRevision) {
            if (! in_array($order->status, ['draft', 'cancelled'], true)) {
                return response()->json([
                    'message' => 'Seules les commandes en brouillon ou refusées peuvent être entièrement modifiées.',
                ], 422);
            }

            $validated = $request->validate([
                'supplier_id' => 'required|exists:suppliers,id',
                'items' => 'required|array|min:1',
                'items.*.product_id' => [
                    'nullable',
                    Rule::exists('products', 'id')->where('store_id', $user->store_id),
                ],
                'items.*.product_name' => 'required|string|max:255',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'items.*.unit' => 'nullable|string|max:50',
                'expected_delivery_date' => 'nullable|date',
                'notes' => 'nullable|string',
            ]);

            return DB::transaction(function () use ($validated, $order, $user) {
                $order->items()->delete();

                foreach ($validated['items'] as $itemData) {
                    $product = ! empty($itemData['product_id'])
                        ? \App\Models\Product::where('store_id', $user->store_id)->find($itemData['product_id'])
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

                $order->update([
                    'supplier_id' => $validated['supplier_id'],
                    'user_id' => $user->id,
                    'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'status' => 'draft',
                    'supplier_token' => null,
                    'supplier_token_expires_at' => null,
                    'supplier_responded_at' => null,
                    'supplier_response_seen_at' => null,
                    'supplier_response_note' => null,
                    'supplier_confirmation_note' => null,
                    'total_amount' => 0,
                ]);

                $order->load(['supplier', 'items.product', 'store', 'deliveryReceivedBy']);
                $order->setAttribute(
                    'establishment_contacts',
                    optional($order->store)->supplierOrderContactsPayload() ?? ['store' => null, 'staff' => []]
                );

                return response()->json($order);
            });
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:draft,pending,confirmed,delivered,cancelled',
            'expected_delivery_date' => 'nullable|date',
            'delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $order->update($validated);

        $order->load(['supplier', 'items.product', 'store', 'deliveryReceivedBy']);
        $order->setAttribute(
            'establishment_contacts',
            optional($order->store)->supplierOrderContactsPayload() ?? ['store' => null, 'staff' => []]
        );

        return response()->json($order);
    }

    public function completeDelivery(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user || ! $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        if (! $user->isAdmin() && ! $user->isChef() && ! $user->isDirector()) {
            return response()->json([
                'message' => 'Seuls l\'administrateur, le chef ou le directeur peuvent valider la réception de livraison.',
            ], 403);
        }

        $order = Order::with(['supplier', 'items', 'store'])->findOrFail($id);

        if ($order->store_id !== $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $this->authorize('update', $order);

        if ($order->status !== 'confirmed') {
            return response()->json([
                'message' => 'Seules les commandes confirmées par le fournisseur peuvent être réceptionnées.',
            ], 422);
        }

        $validated = $request->validate([
            'delivery_photo' => 'required|string|max:1500000',
            'supplier_delivery_signature' => 'required|string|max:1500000',
            'establishment_delivery_signature' => 'required|string|max:1500000',
        ]);

        foreach (['delivery_photo', 'supplier_delivery_signature', 'establishment_delivery_signature'] as $field) {
            if (! str_starts_with($validated[$field], 'data:image/')) {
                return response()->json([
                    'message' => 'Format d\'image invalide (« '.$field.' »).',
                ], 422);
            }
        }

        try {
            $order->update([
                'status' => 'delivered',
                'delivery_date' => now()->toDateString(),
                'delivery_photo' => $validated['delivery_photo'],
                'supplier_delivery_signature' => $validated['supplier_delivery_signature'],
                'establishment_delivery_signature' => $validated['establishment_delivery_signature'],
                'delivery_received_by_user_id' => $user->id,
            ]);
        } catch (QueryException $e) {
            Log::error('completeDelivery: échec SQL', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            if (str_contains($e->getMessage(), 'Unknown column')
                || str_contains($e->getMessage(), 'no such column')
                || ($e->errorInfo[1] ?? null) === 1054) {
                return response()->json([
                    'message' => 'Base de données incomplète : exécutez `php artisan migrate` (colonnes livraison fournisseur).',
                ], 500);
            }

            return response()->json([
                'message' => 'Erreur lors de l’enregistrement en base. Vérifiez les logs serveur.',
            ], 500);
        }

        // Ne pas charger items.product : le champ photo des produits (souvent énorme) peut faire échouer le JSON ou saturer la mémoire.
        $order->load(['supplier', 'user', 'items', 'store', 'deliveryReceivedBy']);
        $order->setAttribute(
            'establishment_contacts',
            optional($order->store)->supplierOrderContactsPayload() ?? ['store' => null, 'staff' => []]
        );
        $order->makeHidden([
            'delivery_photo',
            'supplier_delivery_signature',
            'establishment_delivery_signature',
        ]);

        return response()->json([
            'message' => 'Livraison enregistrée.',
            'order' => $order,
        ]);
    }

    /**
     * Badge tableau de bord : réponses fournisseur pas encore consultées par l'établissement.
     */
    public function unseenSupplierResponsesCount(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->store_id) {
            return response()->json(['count' => 0]);
        }
        if (! $user->isAdmin() && ! $user->isChef() && ! $user->isDirector()) {
            return response()->json(['count' => 0]);
        }

        $count = Order::query()
            ->where('store_id', $user->store_id)
            ->whereNotNull('supplier_responded_at')
            ->whereNull('supplier_response_seen_at')
            ->whereIn('status', ['confirmed', 'cancelled'])
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Marque les réponses fournisseur comme vues (ex. ouverture de l'écran commandes fournisseurs).
     */
    public function acknowledgeSupplierResponses(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->store_id) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        if (! $user->isAdmin() && ! $user->isChef() && ! $user->isDirector()) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        $this->authorize('viewAny', Order::class);

        Order::query()
            ->where('store_id', $user->store_id)
            ->whereNotNull('supplier_responded_at')
            ->whereNull('supplier_response_seen_at')
            ->whereIn('status', ['confirmed', 'cancelled'])
            ->update(['supplier_response_seen_at' => now()]);

        return response()->json(['message' => 'Ok']);
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

            $fresh = $order->fresh(['supplier', 'user', 'items.product', 'store', 'deliveryReceivedBy']);
            if ($fresh) {
                $fresh->setAttribute(
                    'establishment_contacts',
                    optional($fresh->store)->supplierOrderContactsPayload() ?? ['store' => null, 'staff' => []]
                );
            }

            return response()->json([
                'message' => 'Commande envoyée au fournisseur avec succès.',
                'order' => $fresh,
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

    /**
     * Réponse API ou page HTML pour les actions fournisseur publiques (liens e-mail).
     */
    protected function supplierPublicRespond(Request $request, string $message, int $status = 404): \Symfony\Component\HttpFoundation\Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return response()->view('supplier-orders.token-unavailable', [
            'message' => $message,
        ], $status);
    }

    public function supplierShowByToken(Request $request, string $token)
    {
        $order = Order::with(['supplier', 'store', 'items'])
            ->where('supplier_token', $token)
            ->first();

        if (!$order) {
            return $this->supplierPublicRespond($request, 'Ce lien est invalide, a expiré ou a déjà été utilisé après une réponse.', 404);
        }

        if (!$order->supplier_token_expires_at || $order->supplier_token_expires_at->isPast()) {
            return $this->supplierPublicRespond($request, 'Ce lien a expiré.', 410);
        }

        return response()->json($order);
    }

    public function supplierRespondByToken(Request $request, string $token, string $decision)
    {
        if (!in_array($decision, ['confirmed', 'cancelled'], true)) {
            return $this->supplierPublicRespond($request, 'Décision invalide.', 422);
        }

        $order = Order::where('supplier_token', $token)->first();
        if (!$order) {
            return $this->supplierPublicRespond($request, 'Ce lien est invalide, a expiré ou a déjà été utilisé après une réponse (confirmation ou refus).', 404);
        }

        if (!$order->supplier_token_expires_at || $order->supplier_token_expires_at->isPast()) {
            return $this->supplierPublicRespond($request, 'Ce lien a expiré.', 410);
        }

        if (in_array($order->status, ['delivered'], true)) {
            return $this->supplierPublicRespond($request, 'Cette commande est déjà livrée.', 422);
        }

        if ($order->status !== 'pending') {
            return $this->supplierPublicRespond($request, 'Cette commande a déjà été traitée ou le lien n’est plus actif. Une seule réponse (confirmer ou refuser) est possible par envoi.', 410);
        }

        $refusalNote = trim((string) ($request->input('note', $request->query('note', ''))));

        if ($decision === 'cancelled' && $refusalNote === '') {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Le motif de refus est obligatoire.',
                ], 422);
            }

            return response()->view('supplier-orders.reject-reason', [
                'token' => $token,
            ], 422);
        }

        // Lien e-mail « Confirmer » : page avec note optionnelle, puis POST (navigateur)
        if ($decision === 'confirmed' && ! $request->expectsJson() && $request->isMethod('GET')) {
            return response()->view('supplier-orders.confirm-order', [
                'token' => $token,
            ]);
        }

        $confirmationNote = '';

        if ($decision === 'confirmed') {
            $validatedNote = $request->validate([
                'confirmation_note' => 'nullable|string|max:2000',
            ]);
            $confirmationNote = trim((string) ($validatedNote['confirmation_note'] ?? ''));
            if ($confirmationNote === '' && $request->expectsJson()) {
                $confirmationNote = trim((string) $request->input('note', ''));
                if (strlen($confirmationNote) > 2000) {
                    $confirmationNote = substr($confirmationNote, 0, 2000);
                }
            }
        }

        if ($decision === 'cancelled') {
            $order->update([
                'status' => 'cancelled',
                'supplier_responded_at' => now(),
                'supplier_response_seen_at' => null,
                'supplier_response_note' => $refusalNote,
                'supplier_token' => null,
                'supplier_token_expires_at' => null,
            ]);
        } else {
            $order->update([
                'status' => 'confirmed',
                'supplier_responded_at' => now(),
                'supplier_response_seen_at' => null,
                'supplier_confirmation_note' => $confirmationNote !== '' ? $confirmationNote : null,
                'supplier_token' => null,
                'supplier_token_expires_at' => null,
            ]);
        }

        $freshOrder = $order->fresh(['supplier', 'store', 'items']);

        try {
            $this->notificationService->notifySupplierOrderResponse($freshOrder, $decision);
        } catch (\Throwable $e) {
            Log::warning('notifySupplierOrderResponse échoué', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $request->expectsJson()) {
            if ($decision === 'confirmed') {
                $pdf = Pdf::loadView('pdf.supplier-order-confirmation', [
                    'order' => $freshOrder,
                ]);

                $fileName = 'commande-fournisseur-'.$freshOrder->order_number.'.pdf';

                return $pdf->download($fileName);
            }

            return response()->view('supplier-orders.response-status', [
                'status' => 'cancelled',
                'message' => 'Refus de commande enregistré avec succès.',
                'reason' => $refusalNote,
            ]);
        }

        return response()->json([
            'message' => $decision === 'confirmed'
                ? 'Commande confirmée par le fournisseur.'
                : 'Commande refusée par le fournisseur.',
            'order' => $freshOrder,
        ]);
    }
}
