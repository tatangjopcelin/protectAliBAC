<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\ZoneController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ShoppingListController;
use App\Http\Controllers\Api\AuthController;

// Routes d'authentification (publiques)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/user', [AuthController::class, 'user'])->middleware('auth:sanctum');

// Routes publiques (peut être sécurisées plus tard)
Route::apiResource('stores', StoreController::class);
Route::apiResource('zones', ZoneController::class);
Route::apiResource('categories', CategoryController::class);
Route::apiResource('suppliers', SupplierController::class);

// Routes spéciales pour les produits (AVANT apiResource pour éviter les conflits)
Route::get('/products/expiring/{days}', [ProductController::class, 'expiring']);
Route::get('/products/expired', [ProductController::class, 'expired']);
Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
Route::get('/products/fifo/{productId?}', [ProductController::class, 'fifo']);
Route::post('/products/scan', [ProductController::class, 'scan']); // Scanner un produit
Route::get('/products/search/barcode', [ProductController::class, 'searchByBarcode']); // Recherche par code-barres
Route::post('/products/handle-expired', [ProductController::class, 'handleExpiredProducts']); // Gérer tous les produits périmés
Route::get('/products/{id}/trace-history', [ProductController::class, 'traceHistory']); // Historique de traçabilité
Route::post('/products/{id}/add-stock', [ProductController::class, 'addStock']); // Ajouter du stock à un produit
Route::post('/products/{id}/mark-expired', [ProductController::class, 'markAsExpired']); // Marquer un produit comme périmé
Route::apiResource('products', ProductController::class);

Route::apiResource('stock-movements', StockMovementController::class);

// Routes spéciales pour les recettes
Route::post('/recipes/{recipe}/prepare', [RecipeController::class, 'prepare']);
Route::apiResource('recipes', RecipeController::class);

// Routes spéciales pour les alertes (AVANT apiResource pour éviter les conflits)
Route::get('/alerts/unread', [AlertController::class, 'unread']);
Route::post('/alerts/{alert}/read', [AlertController::class, 'markAsRead']);
Route::apiResource('alerts', AlertController::class);

// Routes pour le Dashboard (protégées)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
});

// Routes pour les commandes fournisseurs
Route::apiResource('orders', OrderController::class);
Route::post('/orders/generate', [OrderController::class, 'generate']); // Générer une commande automatique
Route::get('/products/{productId}/compare-prices', [OrderController::class, 'comparePrices']); // Comparer les prix fournisseurs

// Routes pour l'IA & Recommandations
Route::get('/ai/suggest-recipes', [AIController::class, 'suggestRecipes']); // Suggestions de recettes
Route::get('/ai/predict-consumption/{productId}', [AIController::class, 'predictConsumption']); // Prédiction de consommation
Route::get('/ai/suggest-orders', [AIController::class, 'suggestOrders']); // Suggestions de commandes
Route::get('/ai/detect-anomalies', [AIController::class, 'detectAnomalies']); // Détection d'anomalies
Route::get('/ai/waste-reduction', [AIController::class, 'wasteReductionSuggestions']); // Suggestions de réduction de gaspillage
Route::get('/ai/suggestions', [AIController::class, 'index']); // Liste des suggestions
Route::put('/ai/suggestions/{id}/status', [AIController::class, 'updateStatus']); // Mettre à jour le statut d'une suggestion

// Routes pour les notifications
Route::get('/notifications', [NotificationController::class, 'index']);
Route::get('/notifications/unread', [NotificationController::class, 'unread']);
Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

// Routes pour les préférences de notification
Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
Route::post('/notification-preferences', [NotificationPreferenceController::class, 'store']);
Route::put('/notification-preferences/{id}', [NotificationPreferenceController::class, 'update']);

// Routes pour la gestion des rôles et permissions
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/roles/permissions', [RoleController::class, 'permissions']);
    Route::get('/roles/{role}/permissions', [RoleController::class, 'rolePermissions']);
    Route::get('/roles/{role}/users', [RoleController::class, 'usersByRole']);
    Route::get('/my-permissions', [RoleController::class, 'myPermissions']);
    Route::post('/roles/{role}/permissions', [RoleController::class, 'assignPermission']);
    Route::delete('/roles/{role}/permissions/{permissionId}', [RoleController::class, 'revokePermission']);
});

// Routes pour la gestion des utilisateurs
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('users', UserController::class);
    Route::put('/users/{id}/role', [UserController::class, 'updateRole']); // Modifier le rôle (Admin uniquement)
});

// Routes pour la liste d'achats (tous les utilisateurs authentifiés peuvent ajouter)
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('shopping-list', ShoppingListController::class);
    Route::get('/shopping-list/stats', [ShoppingListController::class, 'stats']); // Statistiques
    Route::post('/shopping-list/{id}/mark-ordered', [ShoppingListController::class, 'markAsOrdered']); // Marquer comme commandé
    Route::post('/shopping-list/{id}/mark-received', [ShoppingListController::class, 'markAsReceived']); // Marquer comme reçu
    Route::post('/shopping-list/{id}/cancel', [ShoppingListController::class, 'cancel']); // Annuler
});
