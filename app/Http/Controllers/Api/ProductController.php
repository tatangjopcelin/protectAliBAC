<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\AlertService;
use App\Services\NotificationService;
use App\Notifications\ProductCreatedNotification;
use App\Notifications\ProductUpdatedNotification;
use App\Notifications\ProductStockAddedNotification;
use App\Notifications\ProductStockReducedNotification;
use App\Notifications\ProductExpiredNotification;
use App\Notifications\ProductDeletedNotification;
use App\Notifications\ProductsExpiredBulkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProductController extends Controller
{
    protected $alertService;
    protected $notificationService;

    public function __construct(AlertService $alertService, NotificationService $notificationService)
    {
        $this->alertService = $alertService;
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $query = Product::with(['category', 'supplier', 'zone.store'])
            ->where('is_active', true);

        // Filtrer par établissement via la relation zone
        if ($user->store_id) {
            $query->whereHas('zone', function($q) use ($user) {
                $q->where('store_id', $user->store_id);
            });
        }

        // Filtres
        if ($request->has('zone_id')) {
            // Ajouter le filtre zone_id directement - le whereHas ci-dessus garantit déjà la sécurité
            $query->where('zone_id', $request->zone_id);
        }
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        // Tri par date de péremption (FIFO)
        if ($request->has('sort') && $request->sort === 'expiration') {
            $query->orderBy('expiration_date', 'asc');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $products = $query->get();

        // Recalculer le statut à partir de la date de péremption pour un affichage toujours à jour
        foreach ($products as $product) {
            $product->status = $product->getComputedStatus();
        }

        // Filtre par statut (appliqué après recalcul pour cohérence avec l'IHM)
        if ($request->has('status')) {
            $products = $products->where('status', $request->status)->values();
        }

        // Charger le créateur pour chaque produit (première trace "created")
        foreach ($products as $product) {
            $firstTrace = \App\Models\ProductTrace::where('product_id', $product->id)
                ->where('action', 'created')
                ->with('user')
                ->orderBy('created_at', 'asc')
                ->first();

            if ($firstTrace && $firstTrace->user) {
                $product->creator = $firstTrace->user;
            }
        }

        return response()->json($products);
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
        $policy = new \App\Policies\ProductPolicy();
        if (!$policy->create($user)) {
            \Log::warning('Tentative de création de produit refusée', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'user_email' => $user->email
            ]);
            return response()->json([
                'message' => 'Accès refusé',
                'error' => 'Vous n\'avez pas la permission de créer un produit. Rôles autorisés: Admin, Chef, Directeur, Magasinier, Boucher, Cuisinier',
                'user_role' => $user->role,
                'debug' => [
                    'isAdmin' => $user->isAdmin(),
                    'isChef' => $user->isChef(),
                    'isDirector' => $user->isDirector(),
                    'isStorekeeper' => $user->isStorekeeper(),
                    'isButcher' => $user->isButcher(),
                    'isCook' => $user->isCook(),
                ]
            ], 403);
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'zone_id' => 'required|exists:zones,id',
            'quantity' => 'required|numeric|min:0',
            'unit' => 'required|string',
            'min_quantity' => 'nullable|numeric|min:0',
            'reception_date' => 'required|date',
            'expiration_date' => 'required|date|after:reception_date',
            'purchase_price' => 'nullable|numeric|min:0',
            'photo' => 'required|string', // Photo obligatoire pour la traçabilité
            'barcode' => 'required|string|max:255', // QR code obligatoire - doit être scanné depuis l'étiquette du produit
            'notes' => 'nullable|string',
            // Champs de traçabilité (5 informations essentielles - tous nullable)
            'batch_number' => 'nullable|string|max:255',           // 1. Numéro de lot
            'manufacturing_date' => 'nullable|date',              // 2. Date de fabrication
            'factory_name' => 'nullable|string|max:255',          // 3. Nom de l'usine
            'origin_country' => 'nullable|string|max:100',        // 4. Pays d'origine
            'certificate_number' => 'nullable|string|max:255',    // 5. Numéro de certificat
        ]);

        // Vérifier que la zone appartient au même établissement que l'utilisateur
        $zone = \App\Models\Zone::find($validated['zone_id']);
        if (!$zone) {
            return response()->json([
                'error' => 'Zone introuvable',
                'message' => 'La zone sélectionnée n\'existe pas.'
            ], 422);
        }
        if ($user->store_id && $zone->store_id !== $user->store_id) {
            return response()->json([
                'error' => 'Zone non accessible',
                'message' => 'La zone sélectionnée n\'appartient pas à votre établissement.'
            ], 403);
        }

        // Vérifier l'unicité du code-barres (le QR code doit être unique et scanné depuis l'étiquette)
        $existing = Product::where('barcode', $validated['barcode'])->where('is_active', true)->first();
        if ($existing) {
            return response()->json([
                'error' => 'Ce code-barres existe déjà',
                'message' => 'Ce QR code est déjà utilisé par un autre produit. Veuillez scanner le QR code unique de ce produit.',
                'existing_product' => $existing->load(['category', 'zone'])
            ], 422);
        }

        // Logger les données reçues pour debug
        \Log::info('Création produit - Données reçues', [
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'request_data' => $request->all(),
            'validated_data' => $validated,
            'traceability_fields' => [
                'batch_number' => $validated['batch_number'] ?? 'NON FOURNI',
                'manufacturing_date' => $validated['manufacturing_date'] ?? 'NON FOURNI',
                'factory_name' => $validated['factory_name'] ?? 'NON FOURNI',
                'origin_country' => $validated['origin_country'] ?? 'NON FOURNI',
                'certificate_number' => $validated['certificate_number'] ?? 'NON FOURNI',
            ]
        ]);

        $product = Product::create($validated);
        
        // Recharger pour vérifier que les données sont bien enregistrées
        $product->refresh();
        \Log::info('Produit créé - Vérification', [
            'product_id' => $product->id,
            'batch_number' => $product->batch_number ?? 'NULL',
            'manufacturing_date' => $product->manufacturing_date ?? 'NULL',
            'factory_name' => $product->factory_name ?? 'NULL',
            'origin_country' => $product->origin_country ?? 'NULL',
            'certificate_number' => $product->certificate_number ?? 'NULL',
        ]);
        
        $product->load('zone.store'); // Charger les relations pour la localisation
        $product->updateStatus();
        $this->alertService->checkProduct($product);

        // Envoyer une notification par email à tous les autres utilisateurs
        $this->notifyAllUsersExcept($user, new ProductCreatedNotification($product, $user));

        // Enregistrer la création dans la traçabilité avec toutes les informations d'origine
        $traceMetadata = [
            'barcode' => $product->barcode,
            'name' => $product->name,
            // Informations de traçabilité (5 informations essentielles)
            'batch_number' => $product->batch_number,
            'manufacturing_date' => $product->manufacturing_date ? (is_string($product->manufacturing_date) ? $product->manufacturing_date : $product->manufacturing_date->format('Y-m-d')) : null,
            'factory_name' => $product->factory_name,
            'origin_country' => $product->origin_country,
            'certificate_number' => $product->certificate_number,
            // Informations du fournisseur
            'supplier_id' => $product->supplier_id,
            'supplier_name' => $product->supplier?->name,
            'supplier_contact' => $product->supplier?->contact_name,
            'supplier_email' => $product->supplier?->email,
            'supplier_phone' => $product->supplier?->phone,
            'supplier_address' => $product->supplier?->address,
        ];
        $this->recordTrace($product->id, 'created', $request->user()?->id, $traceMetadata);

        // Les notifications par email sont déjà envoyées via notifyAllUsersExcept() ci-dessus

        return response()->json($product->load(['category', 'supplier', 'zone.store']), 201);
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

        $product = Product::with(['category', 'supplier', 'zone.store', 'stockMovements', 'alerts'])
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->where('id', $id)
            ->firstOrFail();
        
        // Enregistrer la consultation dans la traçabilité
        $this->recordTrace($product->id, 'viewed', $user->id, [
            'barcode' => $product->barcode
        ]);

        $product->status = $product->getComputedStatus();

        return response()->json($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $product = Product::where('id', $id)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->firstOrFail();
        
        // Vérifier les permissions avec la Policy
        if (!(new \App\Policies\ProductPolicy())->update($user, $product)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'zone_id' => 'sometimes|exists:zones,id',
            'quantity' => 'sometimes|numeric|min:0',
            'unit' => 'sometimes|string',
            'min_quantity' => 'nullable|numeric|min:0',
            'reception_date' => 'sometimes|date',
            'expiration_date' => 'sometimes|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'photo' => 'sometimes|string', // Photo optionnelle lors de la mise à jour (peut être omise)
            'barcode' => 'nullable|string',
            'notes' => 'nullable|string',
            // Champs de traçabilité (5 informations essentielles - tous nullable)
            'batch_number' => 'nullable|string|max:255',           // 1. Numéro de lot
            'manufacturing_date' => 'nullable|date',              // 2. Date de fabrication
            'factory_name' => 'nullable|string|max:255',          // 3. Nom de l'usine
            'origin_country' => 'nullable|string|max:100',        // 4. Pays d'origine
            'certificate_number' => 'nullable|string|max:255',    // 5. Numéro de certificat
        ]);

        // Vérifier que la zone (si modifiée) appartient au même établissement que l'utilisateur
        if (isset($validated['zone_id']) && $validated['zone_id'] != $product->zone_id) {
            $zone = \App\Models\Zone::find($validated['zone_id']);
            if (!$zone) {
                return response()->json([
                    'error' => 'Zone introuvable',
                    'message' => 'La zone sélectionnée n\'existe pas.'
                ], 422);
            }
            if ($user->store_id && $zone->store_id !== $user->store_id) {
                return response()->json([
                    'error' => 'Zone non accessible',
                    'message' => 'La zone sélectionnée n\'appartient pas à votre établissement.'
                ], 403);
            }
        }

        // Logger les données reçues pour debug
        \Log::info('Mise à jour produit', [
            'product_id' => $product->id,
            'user_id' => $request->user()?->id,
            'validated_data' => $validated,
            'traceability_fields' => [
                'batch_number' => $validated['batch_number'] ?? null,
                'manufacturing_date' => $validated['manufacturing_date'] ?? null,
                'factory_name' => $validated['factory_name'] ?? null,
                'origin_country' => $validated['origin_country'] ?? null,
                'certificate_number' => $validated['certificate_number'] ?? null,
            ]
        ]);

        $product->update($validated);
        
        // Recharger le produit pour vérifier que les données sont bien enregistrées
        $product->refresh();
        \Log::info('Produit après mise à jour', [
            'product_id' => $product->id,
            'batch_number' => $product->batch_number,
            'factory_name' => $product->factory_name,
            'origin_country' => $product->origin_country,
            'certificate_number' => $product->certificate_number,
        ]);
        $product->load('zone.store'); // Charger les relations pour la localisation
        $product->updateStatus();
        $this->alertService->checkProduct($product);

        // Envoyer une notification par email à tous les autres utilisateurs
        $this->notifyAllUsersExcept($user, new ProductUpdatedNotification($product, $user));

        // Enregistrer la modification dans la traçabilité
        $this->recordTrace($product->id, 'updated', $request->user()?->id, [
            'barcode' => $product->barcode,
            'changes' => array_keys($validated)
        ]);

        // Recharger avec toutes les relations et les champs de traçabilité
        $product->refresh();
        return response()->json($product->load(['category', 'supplier', 'zone.store']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $product = Product::where('id', $id)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->firstOrFail();
        
        if (!(new \App\Policies\ProductPolicy())->delete($user, $product)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        
        // Charger les relations avant la désactivation
        $product->load(['category', 'supplier', 'zone.store']);

        // Supprimer les alertes liées à ce produit (la "suppression" désactive le produit, pas de CASCADE)
        $product->alerts()->delete();

        $product->update(['is_active' => false]);

        // Envoyer une notification par email à tous les autres utilisateurs
        if ($user) {
            $this->notifyAllUsersExcept($user, new ProductDeletedNotification($product, $user));
        }

        return response()->json(['message' => 'Produit désactivé'], 200);
    }

    /**
     * Produits expirant dans X jours
     */
    public function expiring(Request $request, $days = 3)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Convertir en int si c'est un string
        $days = (int) $days;
        $date = Carbon::today()->addDays($days);
        $products = Product::where('is_active', true)
            ->where('expiration_date', '<=', $date)
            ->where('expiration_date', '>=', Carbon::today())
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->orderBy('expiration_date', 'asc')
            ->with(['category', 'zone'])
            ->get();

        return response()->json($products);
    }

    /**
     * Produits périmés
     */
    public function expired(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $products = Product::where('is_active', true)
            ->where('expiration_date', '<', Carbon::today())
            ->where('quantity', '>', 0) // Seulement ceux qui ont encore du stock
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->orderBy('expiration_date', 'asc')
            ->with(['category', 'zone.store'])
            ->get();

        return response()->json($products);
    }

    /**
     * Marquer un produit comme périmé et réduire le stock à 0
     */
    public function markAsExpired(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $product = Product::where('id', $id)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->firstOrFail();
        
        if (!(new \App\Policies\ProductPolicy())->markExpired($user, $product)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        
        // Vérifier si le produit est déjà marqué comme périmé
        if ($product->status === 'expired') {
            return response()->json([
                'message' => 'Ce produit est déjà marqué comme périmé'
            ], 200);
        }

        // Vérifier si le produit est effectivement périmé selon la date
        // Le marquage manuel n'est autorisé que si le produit est déjà périmé
        if (!$product->isExpired()) {
            return response()->json([
                'error' => 'Ce produit n\'est pas encore périmé. Le marquage comme périmé se fait automatiquement lorsque la date de péremption est dépassée.',
                'expiration_date' => $product->expiration_date,
                'is_expired' => false
            ], 400);
        }

        if ($product->quantity <= 0) {
            return response()->json([
                'message' => 'Le stock de ce produit est déjà à zéro'
            ], 200);
        }

        return DB::transaction(function () use ($product, $request) {
            $quantityToRemove = $product->quantity;

            // Créer un mouvement de stock pour enregistrer la perte
            \App\Models\StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()?->id,
                'type' => 'wasted',
                'quantity' => $quantityToRemove,
                'notes' => "Produit périmé - Retiré du stock automatiquement",
            ]);

            // Réduire le stock à 0
            $product->quantity = 0;
            $product->status = 'expired';
            $product->save();

            // Mettre à jour les alertes
            $this->alertService->checkProduct($product);

            // Envoyer une notification par email à tous les autres utilisateurs
            if ($request->user()) {
                $this->notifyAllUsersExcept($request->user(), new ProductExpiredNotification($product, $request->user(), $quantityToRemove));
            }

            return response()->json([
                'message' => "Le produit {$product->name} a été marqué comme périmé et retiré du stock",
                'product' => $product->load(['category', 'supplier', 'zone.store']),
                'quantity_removed' => $quantityToRemove
            ]);
        });
    }

    /**
     * Ajouter du stock à un produit existant
     */
    public function addStock(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $product = Product::where('id', $id)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->firstOrFail();
        
        if (!(new \App\Policies\ProductPolicy())->addStock($user, $product)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'reception_date' => 'nullable|date',
            'expiration_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'zone_id' => 'nullable|exists:zones,id',
            'notes' => 'nullable|string',
            'photo' => 'nullable|string', // Photo en base64
            'force_update_date' => 'nullable|boolean', // Forcer la mise à jour même si date différente
            'create_new_product' => 'nullable|boolean', // Créer un nouveau produit si date différente
        ]);

        return DB::transaction(function () use ($product, $validated, $request) {
            $quantityToAdd = $validated['quantity'];
            $oldQuantity = $product->quantity;
            
            // Comparaison en date seule : même date calendaire que sur la fiche (fuseau app).
            $appTz = config('app.timezone', 'Europe/Paris');
            $hasDifferentExpirationDate = false;
            if (isset($validated['expiration_date'])) {
                $newExpirationDate = preg_match('/^\d{4}-\d{2}-\d{2}/', $validated['expiration_date'])
                    ? substr($validated['expiration_date'], 0, 10)
                    : Carbon::parse($validated['expiration_date'])->format('Y-m-d');
                // Utiliser l'attribut Carbon du modèle (comme l'API pour la fiche) puis format en fuseau app
                $currentExpirationDate = $product->expiration_date
                    ? $product->expiration_date->copy()->timezone($appTz)->format('Y-m-d')
                    : null;
                
                if ($currentExpirationDate && $newExpirationDate !== $currentExpirationDate) {
                    $hasDifferentExpirationDate = true;
                    
                    if (!($validated['force_update_date'] ?? false) && !($validated['create_new_product'] ?? false)) {
                        return response()->json([
                            'error' => 'La date de péremption est différente du produit existant',
                            'current_expiration_date' => $currentExpirationDate,
                            'new_expiration_date' => $newExpirationDate,
                            'suggestions' => [
                                'Utilisez "force_update_date": true pour mettre à jour la date du produit existant',
                                'Utilisez "create_new_product": true pour créer un nouveau produit avec cette date',
                                'Ne fournissez pas expiration_date pour garder la date actuelle'
                            ]
                        ], 422);
                    }
                    
                    // Si on crée un nouveau produit
                    if ($validated['create_new_product'] ?? false) {
                        return $this->createNewProductFromExisting($product, $validated, $request);
                    }
                }
            }

            // Ajouter au produit existant
            $newQuantity = $oldQuantity + $quantityToAdd;

            // Mettre à jour les dates seulement si force_update_date est true ou si la date est la même
            if (isset($validated['reception_date']) && ($validated['force_update_date'] ?? false || !$hasDifferentExpirationDate)) {
                $product->reception_date = $validated['reception_date'];
            }
            if (isset($validated['expiration_date']) && ($validated['force_update_date'] ?? false || !$hasDifferentExpirationDate)) {
                $product->expiration_date = $validated['expiration_date'];
                $product->updateStatus();
            }
            if (isset($validated['purchase_price'])) {
                // Optionnel : calculer une moyenne pondérée au lieu de remplacer
                $product->purchase_price = $validated['purchase_price'];
            }
            if (isset($validated['supplier_id'])) {
                $product->supplier_id = $validated['supplier_id'];
            }
            // Mettre à jour la photo si fournie
            if (isset($validated['photo']) && !empty($validated['photo'])) {
                $product->photo = $validated['photo'];
            }
            // Note: On ne change pas la zone si elle est différente, car cela pourrait être un nouveau lot dans une autre zone
            // Si nécessaire, l'utilisateur peut créer un nouveau produit

            // Ajouter la quantité
            $product->quantity = $newQuantity;
            $product->save();

            // Créer un mouvement de stock pour l'entrée
            \App\Models\StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()?->id,
                'type' => 'entry',
                'quantity' => $quantityToAdd,
                'notes' => $validated['notes'] ?? "Ajout de stock - Quantité ajoutée: {$quantityToAdd} {$product->unit}",
            ]);

            // Vérifier les alertes
            $product->load('zone.store');
            $this->alertService->checkProduct($product);

            // Enregistrer dans la traçabilité
            $this->recordTrace($product->id, 'stock_added', $request->user()?->id, [
                'quantity_added' => $quantityToAdd,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity
            ]);

            // Envoyer une notification par email à tous les autres utilisateurs
            if ($request->user()) {
                $this->notifyAllUsersExcept($request->user(), new ProductStockAddedNotification($product, $request->user(), $quantityToAdd, $oldQuantity, $newQuantity));
            }

            return response()->json([
                'message' => "Stock ajouté avec succès",
                'product' => $product->load(['category', 'supplier', 'zone.store']),
                'stock_info' => [
                    'old_quantity' => $oldQuantity,
                    'quantity_added' => $quantityToAdd,
                    'new_quantity' => $newQuantity,
                    'unit' => $product->unit
                ]
            ]);
        });
    }

    /**
     * Réduire le stock d'un produit
     */
    public function reduceStock(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }
        
        $product = Product::where('id', $id)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->firstOrFail();
        
        if (!(new \App\Policies\ProductPolicy())->reduceStock($user, $product)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'nullable|string|max:255', // Raison de la réduction (utilisé, gaspillé, perdu, etc.)
            'notes' => 'nullable|string',
            'type' => 'nullable|string|in:used,wasted,exit,transformed', // Type de mouvement de stock
        ]);

        return DB::transaction(function () use ($product, $validated, $request) {
            $quantityToReduce = $validated['quantity'];
            $oldQuantity = $product->quantity;
            
            // Vérifier que le stock est suffisant
            if ($oldQuantity < $quantityToReduce) {
                return response()->json([
                    'error' => 'Stock insuffisant',
                    'current_stock' => $oldQuantity,
                    'requested_reduction' => $quantityToReduce,
                    'unit' => $product->unit
                ], 400);
            }

            // Calculer la nouvelle quantité
            $newQuantity = $oldQuantity - $quantityToReduce;

            // Mettre à jour le stock
            $product->quantity = $newQuantity;
            $product->save();

            // Déterminer le type de mouvement
            $movementType = $validated['type'] ?? 'exit'; // Par défaut: sortie
            $reason = $validated['reason'] ?? 'Réduction de stock';
            
            // Créer un mouvement de stock pour enregistrer la réduction
            \App\Models\StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $request->user()?->id,
                'type' => $movementType,
                'quantity' => $quantityToReduce,
                'notes' => $validated['notes'] ?? "Réduction de stock - {$reason}. Quantité retirée: {$quantityToReduce} {$product->unit}",
            ]);

            // Mettre à jour le statut du produit si nécessaire
            $product->load('zone.store');
            $product->updateStatus();
            $this->alertService->checkProduct($product);

            // Enregistrer dans la traçabilité
            $this->recordTrace($product->id, 'stock_removed', $request->user()?->id, [
                'quantity_removed' => $quantityToReduce,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'reason' => $reason,
                'movement_type' => $movementType
            ]);

            // Envoyer une notification par email à tous les autres utilisateurs
            if ($request->user()) {
                $this->notifyAllUsersExcept($request->user(), new ProductStockReducedNotification($product, $request->user(), $quantityToReduce, $oldQuantity, $newQuantity, $reason, $movementType));
            }

            return response()->json([
                'message' => "Stock réduit avec succès",
                'product' => $product->load(['category', 'supplier', 'zone.store']),
                'stock_info' => [
                    'old_quantity' => $oldQuantity,
                    'quantity_removed' => $quantityToReduce,
                    'new_quantity' => $newQuantity,
                    'unit' => $product->unit
                ]
            ]);
        });
    }

    /**
     * Créer un nouveau produit à partir d'un produit existant (pour un nouveau lot avec date différente)
     */
    private function createNewProductFromExisting(Product $existingProduct, array $validated, Request $request)
    {
        // Vérifier que le barcode est fourni (obligatoire)
        if (empty($validated['barcode'])) {
            return response()->json([
                'error' => 'Le QR code est obligatoire',
                'message' => 'Pour créer un nouveau produit, vous devez scanner le QR code unique de l\'étiquette du produit.'
            ], 422);
        }

        // Vérifier l'unicité du barcode
        $existing = Product::where('barcode', $validated['barcode'])->where('is_active', true)->first();
        if ($existing) {
            return response()->json([
                'error' => 'Ce code-barres existe déjà',
                'message' => 'Ce QR code est déjà utilisé par un autre produit. Veuillez scanner le QR code unique de ce produit.',
                'existing_product' => $existing->load(['category', 'zone'])
            ], 422);
        }

        // Créer un nouveau produit avec les mêmes caractéristiques mais nouvelle date
        $newProductData = [
            'name' => $existingProduct->name,
            'category_id' => $existingProduct->category_id,
            'supplier_id' => $validated['supplier_id'] ?? $existingProduct->supplier_id,
            'zone_id' => $validated['zone_id'] ?? $existingProduct->zone_id,
            'quantity' => $validated['quantity'],
            'unit' => $existingProduct->unit,
            'min_quantity' => $existingProduct->min_quantity,
            'reception_date' => $validated['reception_date'] ?? Carbon::today(),
            'expiration_date' => $validated['expiration_date'],
            'purchase_price' => $validated['purchase_price'] ?? $existingProduct->purchase_price,
            'photo' => $validated['photo'] ?? $existingProduct->photo, // Utiliser la nouvelle photo si fournie, sinon garder l'ancienne
            'barcode' => $validated['barcode'], // Le barcode est obligatoire et doit être scanné
            'notes' => ($validated['notes'] ?? '') . " - Nouveau lot (date de péremption différente)",
        ];

        $newProduct = Product::create($newProductData);
        $newProduct->load('zone.store');
        $newProduct->updateStatus();
        $this->alertService->checkProduct($newProduct);

        // Créer un mouvement de stock pour l'entrée
        \App\Models\StockMovement::create([
            'product_id' => $newProduct->id,
            'user_id' => $request->user()?->id,
            'type' => 'entry',
            'quantity' => $validated['quantity'],
            'notes' => "Création d'un nouveau lot avec date de péremption différente",
        ]);

        // Enregistrer dans la traçabilité
        $this->recordTrace($newProduct->id, 'created', $request->user()?->id, [
            'barcode' => $newProduct->barcode,
            'name' => $newProduct->name,
            'created_from_product_id' => $existingProduct->id
        ]);

        // Notifier tous les utilisateurs de l'ajout du nouveau produit
        if ($request->user()) {
            try {
                $this->notificationService->notifyProductAdded($newProduct, $request->user());
            } catch (\Exception $e) {
                \Log::error('Erreur lors de l\'envoi de notification nouveau produit: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => "Nouveau produit créé pour le lot avec date de péremption différente",
            'product' => $newProduct->load(['category', 'supplier', 'zone.store']),
            'original_product' => $existingProduct->load(['category', 'zone.store']),
            'info' => [
                'reason' => 'Date de péremption différente',
                'original_expiration_date' => $existingProduct->expiration_date,
                'new_expiration_date' => $newProduct->expiration_date,
            ]
        ], 201);
    }

    /**
     * Gérer automatiquement tous les produits périmés (réduire le stock à 0)
     */
    public function handleExpiredProducts(Request $request)
    {
        $expiredProducts = Product::where('is_active', true)
            ->where('expiration_date', '<', Carbon::today())
            ->where('quantity', '>', 0)
            ->with('zone.store')
            ->get();

        $processed = 0;
        $errors = [];

        foreach ($expiredProducts as $product) {
            try {
                DB::transaction(function () use ($product, $request, &$processed) {
                    $quantityToRemove = $product->quantity;

                    // Créer un mouvement de stock
                    \App\Models\StockMovement::create([
                        'product_id' => $product->id,
                        'user_id' => $request->user()?->id,
                        'type' => 'wasted',
                        'quantity' => $quantityToRemove,
                        'notes' => "Produit périmé - Retiré automatiquement du stock",
                    ]);

                    // Réduire le stock à 0
                    $product->quantity = 0;
                    $product->status = 'expired';
                    $product->save();

                    $processed++;
                });
            } catch (\Exception $e) {
                $errors[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'error' => $e->getMessage()
                ];
            }
        }

        // Envoyer une notification par email à tous les autres utilisateurs
        if ($request->user() && $processed > 0) {
            $this->notifyAllUsersExcept($request->user(), new ProductsExpiredBulkNotification($request->user(), $processed, $expiredProducts->count()));
        }

        return response()->json([
            'message' => "Traitement terminé",
            'processed' => $processed,
            'total_found' => $expiredProducts->count(),
            'errors' => $errors
        ]);
    }

    /**
     * Produits en stock bas (strictement filtrés par établissement)
     */
    public function lowStock(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->store_id) {
            return response()->json([]);
        }
        $query = Product::where('is_active', true)
            ->whereHas('zone', function ($q) use ($user) {
                $q->where('store_id', $user->store_id);
            })
            ->where(function ($q) {
                $q->where('min_quantity', '>', 0)
                    ->whereColumn('quantity', '<=', 'min_quantity');
                $q->orWhere(function ($q2) {
                    $q2->where(function ($q3) {
                        $q3->whereNull('min_quantity')->orWhere('min_quantity', '<=', 0);
                    })->where('quantity', '<=', Product::LOW_STOCK_DEFAULT_THRESHOLD);
                });
            })
            ->with(['category', 'zone']);
        $products = $query->get();
        return response()->json($products);
    }

    /**
     * Produits à utiliser en priorité (FIFO)
     */
    public function fifo($productId = null)
    {
        if ($productId) {
            // Pour un produit spécifique, retourner celui avec la date de péremption la plus proche
            $product = Product::where('id', $productId)
                ->where('is_active', true)
                ->orderBy('expiration_date', 'asc')
                ->first();
            
            return response()->json($product);
        }

        // Tous les produits triés par FIFO
        $products = Product::where('is_active', true)
            ->where('quantity', '>', 0)
            ->orderBy('expiration_date', 'asc')
            ->with(['category', 'zone'])
            ->get();

        return response()->json($products);
    }

    /**
     * Scanner un produit par code-barres ou QR code
     * Recherche automatique ou création manuelle
     */
    public function scan(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string',
        ]);

        $barcode = $request->barcode;

        // Rechercher le produit par code-barres
        $product = Product::where('barcode', $barcode)
            ->where('is_active', true)
            ->with(['category', 'supplier', 'zone.store', 'stockMovements' => function($query) {
                $query->orderBy('created_at', 'desc')->limit(10);
            }, 'alerts' => function($query) {
                $query->where('is_read', false)->orderBy('severity', 'desc');
            }])
            ->first();

        if ($product) {
            // Enregistrer le scan dans la traçabilité
            $this->recordTrace($product->id, 'scan', $request->user()?->id, [
                'barcode' => $barcode,
                'action' => 'product_scanned'
            ]);

            // Mettre à jour le statut
            $product->updateStatus();
            
            return response()->json([
                'found' => true,
                'product' => $product,
                'message' => 'Produit trouvé avec succès'
            ]);
        }

        // Produit non trouvé - retourner les informations pour création manuelle
        // Utiliser 200 au lieu de 404 pour éviter que le frontend interprète cela comme une erreur de route
        return response()->json([
            'found' => false,
            'product' => null,
            'barcode' => $barcode,
            'message' => 'Produit non trouvé. Vous pouvez le créer manuellement.',
            'suggestions' => [
                'Vérifiez que le code-barres est correct',
                'Le produit peut ne pas être encore enregistré',
                'Vous pouvez créer le produit manuellement avec ce code-barres'
            ]
        ], 200);
    }

    /**
     * Rechercher un produit par code-barres (sans enregistrer de trace)
     */
    public function searchByBarcode(Request $request)
    {
        $request->validate([
            'barcode' => 'required|string',
        ]);

        $product = Product::where('barcode', $request->barcode)
            ->where('is_active', true)
            ->with(['category', 'supplier', 'zone.store'])
            ->first();

        if (!$product) {
            return response()->json([
                'found' => false,
                'message' => 'Produit non trouvé'
            ], 404);
        }

        $product->updateStatus();

        return response()->json([
            'found' => true,
            'product' => $product
        ]);
    }

    /**
     * Obtenir l'historique complet de traçabilité d'un produit
     */
    public function traceHistory(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $product = Product::where('id', $id)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->firstOrFail();

        $history = [
            'product' => $product->load(['category', 'supplier', 'zone.store']),
            'stock_movements' => $product->stockMovements()
                ->with(['user', 'fromZone', 'toZone', 'recipe'])
                ->orderBy('created_at', 'desc')
                ->get(),
            'alerts_history' => $product->alerts()
                ->orderBy('created_at', 'desc')
                ->get(),
            'traces' => \App\Models\ProductTrace::where('product_id', $id)
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->get(),
        ];

        return response()->json($history);
    }

    /**
     * Enregistrer une trace de traçabilité
     */
    private function recordTrace(int $productId, string $action, ?int $userId, array $metadata = [])
    {
        \App\Models\ProductTrace::create([
            'product_id' => $productId,
            'user_id' => $userId,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Générer un code-barres unique
     */
    private function generateBarcode(): string
    {
        do {
            // Générer un code-barres EAN-13 (13 chiffres)
            $barcode = '200' . str_pad(rand(0, 999999999), 9, '0', STR_PAD_LEFT);
            // Calculer le chiffre de contrôle (simplifié)
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int)$barcode[$i] * (($i % 2 == 0) ? 1 : 3);
            }
            $checkDigit = (10 - ($sum % 10)) % 10;
            $barcode = substr($barcode, 0, 12) . $checkDigit;
            
            $exists = Product::where('barcode', $barcode)->exists();
        } while ($exists);

        return $barcode;
    }

    /**
     * Notifier tous les utilisateurs sauf celui qui a fait l'action
     */
    private function notifyAllUsersExcept(User $excludedUser, $notification)
    {
        try {
            // Récupérer tous les utilisateurs du même établissement sauf celui qui a fait l'action
            $query = User::where('id', '!=', $excludedUser->id)
                ->whereNotNull('email_verified_at'); // Seulement les utilisateurs vérifiés
            
            // Filtrer par store_id si l'utilisateur a un store_id
            if ($excludedUser->store_id) {
                $query->where('store_id', $excludedUser->store_id);
            }
            
            $users = $query->get();

            // Envoyer la notification à chaque utilisateur
            foreach ($users as $user) {
                try {
                    $user->notify($notification);
                } catch (\Exception $e) {
                    \Log::error('Erreur envoi notification à utilisateur', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            \Log::info('Notifications envoyées', [
                'excluded_user_id' => $excludedUser->id,
                'store_id' => $excludedUser->store_id,
                'notified_users_count' => $users->count()
            ]);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de l\'envoi des notifications: ' . $e->getMessage());
        }
    }
}
