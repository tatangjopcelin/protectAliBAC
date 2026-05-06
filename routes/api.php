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
use App\Http\Controllers\Api\ShoppingListItemController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleReplacementController;
use App\Http\Controllers\Api\TimeEntryController;
use App\Http\Controllers\Api\TimeEntryCorrectionController;
use App\Http\Controllers\Api\BreakController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PayrollReportController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\SuperTaskController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\AbsenceController;
use App\Http\Controllers\Api\AccountSettingsController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\WebPushSubscriptionController;
use App\Http\Controllers\Api\SuperAdminController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\InternalMessageController;
use App\Http\Controllers\Api\DemoRequestController;

// Routes d'authentification (publiques)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/user', [AuthController::class, 'user'])->middleware('auth:sanctum');

// Routes pour les tokens push (app mobile)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/push-tokens', [PushTokenController::class, 'store']);
    Route::post('/push-tokens/send-test', [PushTokenController::class, 'sendTest']);
    Route::post('/web-push/subscribe', [WebPushSubscriptionController::class, 'store']);
    Route::delete('/web-push/subscribe', [WebPushSubscriptionController::class, 'destroy']);
});

// Routes pour les paramètres de compte
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/account-settings', [AccountSettingsController::class, 'show']);
    Route::put('/account-settings/profile', [AccountSettingsController::class, 'updateProfile']);
    Route::put('/account-settings/avatar', [AccountSettingsController::class, 'updateAvatar']);
    Route::put('/account-settings/password', [AccountSettingsController::class, 'changePassword']);
});

// Abonnement (carte bancaire uniquement via Stripe Checkout)
Route::middleware('auth:sanctum')->prefix('subscription')->group(function () {
    Route::get('/plans', [SubscriptionController::class, 'plans']);
    Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::get('/status', [SubscriptionController::class, 'status']);
    Route::post('/billing-portal', [SubscriptionController::class, 'billingPortal']);
    Route::post('/verify-checkout-session', [SubscriptionController::class, 'verifyCheckoutSession']);
    Route::post('/sync-from-stripe', [SubscriptionController::class, 'syncFromStripe']);
});

// Routes de vérification d'email
Route::post('/email/verify', [AuthController::class, 'verifyEmail'])->name('verification.verify');
Route::post('/email/resend', [AuthController::class, 'resendVerificationEmail'])->name('verification.resend');

// Routes de réinitialisation de mot de passe
Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->name('password.forgot');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->name('password.reset');
Route::post('/demo-requests', [DemoRequestController::class, 'store']); // Demande de demo (public)
Route::get('/web-push/public-key', [WebPushSubscriptionController::class, 'publicKey']);

// Routes publiques (peut être sécurisées plus tard)
// Route spécifique AVANT apiResource pour éviter les conflits de routage
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/stores/clock-in-verification-method', [StoreController::class, 'updateClockInVerificationMethod']); // Admin: changer méthode de vérification
});
Route::apiResource('stores', StoreController::class);
Route::apiResource('suppliers', SupplierController::class);

// Routes pour les zones (protégées par authentification pour la sécurité multi-establishment)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/zones', [ZoneController::class, 'index']);
    Route::get('/zones/{id}', [ZoneController::class, 'show']);
    Route::post('/zones/{id}/update-temperature', [ZoneController::class, 'updateTemperature']); // Mettre à jour la température d'une zone
    Route::post('/zones', [ZoneController::class, 'store']);
    Route::put('/zones/{id}', [ZoneController::class, 'update']);
    Route::patch('/zones/{id}', [ZoneController::class, 'update']);
    Route::delete('/zones/{id}', [ZoneController::class, 'destroy']);
});

// Routes spéciales pour les produits (AVANT apiResource pour éviter les conflits)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/products/expiring/{days}', [ProductController::class, 'expiring']);
    Route::get('/products/expired', [ProductController::class, 'expired']);
    Route::get('/products/inactive', [ProductController::class, 'inactive']);
    Route::get('/products/low-stock', [ProductController::class, 'lowStock']);
    Route::get('/products/fifo/{productId?}', [ProductController::class, 'fifo']);
    Route::post('/products/scan', [ProductController::class, 'scan']); // Scanner un produit
    Route::get('/products/search/barcode', [ProductController::class, 'searchByBarcode']); // Recherche par code-barres
    Route::post('/products/handle-expired', [ProductController::class, 'handleExpiredProducts']); // Gérer tous les produits périmés
    Route::get('/products/{id}/trace-history', [ProductController::class, 'traceHistory']); // Historique de traçabilité
    Route::post('/products/{id}/add-stock', [ProductController::class, 'addStock']); // Ajouter du stock à un produit
    Route::post('/products/{id}/reduce-stock', [ProductController::class, 'reduceStock']); // Réduire le stock d'un produit
    Route::post('/products/{id}/mark-expired', [ProductController::class, 'markAsExpired']); // Marquer un produit comme périmé
    Route::post('/products/{id}/restore', [ProductController::class, 'restore']); // Restaurer un produit retiré
    Route::delete('/products/{id}/force', [ProductController::class, 'forceDestroy']); // Suppression définitive
    Route::apiResource('products', ProductController::class);

    // Recettes (fiches techniques + préparation / déstockage)
    Route::post('/recipes/{recipe}/prepare', [RecipeController::class, 'prepare']);
    Route::apiResource('recipes', RecipeController::class);

    // Catégories produits (CRUD + permissions système)
    Route::get('/categories', [CategoryController::class, 'index'])->middleware('permission:categories.view');
    Route::get('/categories/{id}', [CategoryController::class, 'show'])->middleware('permission:categories.view');
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:categories.create');
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->middleware('permission:categories.update');
    Route::patch('/categories/{id}', [CategoryController::class, 'update'])->middleware('permission:categories.update');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->middleware('permission:categories.delete');
});

Route::apiResource('stock-movements', StockMovementController::class);

// Routes spéciales pour les alertes (AVANT apiResource pour éviter les conflits)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/alerts/unread', [AlertController::class, 'unread']);
    Route::post('/alerts/mark-all-read', [AlertController::class, 'markAllAsRead']);
    Route::post('/alerts/{alert}/read', [AlertController::class, 'markAsRead']);
    Route::apiResource('alerts', AlertController::class);
});

// Routes pour le Dashboard (protégées)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
});

// Super admin : vue globale des établissements et tickets de support
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/super-admin/stores', [SuperAdminController::class, 'storesOverview']);
    Route::patch('/super-admin/stores/{id}/free-access', [SuperAdminController::class, 'setFreeAccess']);
    Route::get('/super-admin/subscription-balance', [SuperAdminController::class, 'subscriptionBalance']);
    Route::get('/super-admin/stores-for-email', [SuperAdminController::class, 'storesForEmail']);
    Route::post('/super-admin/send-email', [SuperAdminController::class, 'sendEmail']);
    // Tickets de support (contact super admin)
    Route::post('/support-tickets', [SupportTicketController::class, 'store']); // côté établissement
    Route::get('/support-tickets/unread-count', [SupportTicketController::class, 'unreadCount']); // super admin : nombre de messages non lus
    Route::get('/support-tickets/replies-count', [SupportTicketController::class, 'repliesCount']); // admin : nombre de réponses non vues
    Route::post('/support-tickets/mark-replies-seen', [SupportTicketController::class, 'markRepliesSeen']); // admin : marquer les réponses comme vues (à l'entrée sur Contact)
    Route::post('/support-tickets/mark-seen', [SupportTicketController::class, 'markSeen']); // super admin : marquer tout comme lu
    Route::get('/support-tickets', [SupportTicketController::class, 'index']); // liste (super admin = tous, autres = store courant)
    Route::put('/support-tickets/{id}', [SupportTicketController::class, 'update']); // mise à jour (super admin)
    Route::get('/demo-requests', [DemoRequestController::class, 'index']); // super admin: liste des demandes demo
    Route::post('/demo-requests/{id}/mark-read', [DemoRequestController::class, 'markAsRead']); // super admin: marquer une demande comme lue

    // Messagerie interne : fil d'actualité de l'établissement (visible par tous les employés)
    Route::get('/internal-messages/feed', [InternalMessageController::class, 'feed']);
    Route::get('/internal-messages/unread-count', [InternalMessageController::class, 'unreadCount']);
    Route::post('/internal-messages/mark-opened', [InternalMessageController::class, 'markAsOpened']);
    Route::post('/internal-messages/mark-read', [InternalMessageController::class, 'markAsRead']);
    Route::post('/internal-messages', [InternalMessageController::class, 'store']);
    Route::put('/internal-messages/{id}', [InternalMessageController::class, 'update']);
    Route::delete('/internal-messages/{id}', [InternalMessageController::class, 'destroy']);
});

// Routes pour les commandes fournisseurs
Route::apiResource('orders', OrderController::class);
Route::post('/orders/generate', [OrderController::class, 'generate']); // Générer une commande automatique
Route::get('/products/{productId}/compare-prices', [OrderController::class, 'comparePrices']); // Comparer les prix fournisseurs

// Routes pour l'IA & Recommandations (tous les utilisateurs authentifiés)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/ai/test-connection', [AIController::class, 'testConnection']); // Test de connexion Hugging Face
    Route::get('/ai/suggest-recipes', [AIController::class, 'suggestRecipes']); // Suggestions de recettes
    Route::get('/ai/predict-consumption/{productId}', [AIController::class, 'predictConsumption']); // Prédiction de consommation
    Route::get('/ai/suggest-orders', [AIController::class, 'suggestOrders']); // Suggestions de commandes
    Route::get('/ai/detect-anomalies', [AIController::class, 'detectAnomalies']); // Détection d'anomalies
    Route::get('/ai/waste-reduction', [AIController::class, 'wasteReductionSuggestions']); // Suggestions de réduction de gaspillage
    Route::post('/ai/chat', [AIController::class, 'chat']); // Chat direct avec l'IA
    Route::get('/ai/chat/latest', [AIController::class, 'latestChatConversation']); // Dernière conversation IA
    Route::get('/ai/suggestions', [AIController::class, 'index']); // Liste des suggestions
    Route::put('/ai/suggestions/{id}/status', [AIController::class, 'updateStatus']); // Mettre à jour le statut d'une suggestion
});

// Routes pour les notifications (protégées : l'utilisateur doit être connecté pour voir ses notifications)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread', [NotificationController::class, 'unread']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications/mine', [NotificationController::class, 'deleteMine']);
});

// Routes pour les préférences de notification
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'index']);
    Route::post('/notification-preferences', [NotificationPreferenceController::class, 'store']);
    Route::put('/notification-preferences/{id}', [NotificationPreferenceController::class, 'update']);
});

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
    // Route sans paramètre doit être définie AVANT celle avec paramètre
    Route::put('/users/overtime-limit/all', [UserController::class, 'updateOvertimeLimitForAll']); // Modifier la limite pour tous (Admin uniquement)
    Route::put('/users/{id}/overtime-limit', [UserController::class, 'updateOvertimeLimit']); // Modifier la limite d'heures sup (Admin uniquement)
    Route::get('/users/{id}/shared-permissions', [UserController::class, 'getSharedPermissions']);
    Route::put('/users/{id}/shared-permissions', [UserController::class, 'updateSharedPermissions']);
});

// Routes pour la liste d'achats (tous les utilisateurs authentifiés peuvent ajouter)
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('shopping-list', ShoppingListController::class);
    Route::get('/shopping-list/stats', [ShoppingListController::class, 'stats']); // Statistiques
    Route::post('/shopping-list/{id}/mark-ordered', [ShoppingListController::class, 'markAsOrdered']); // Marquer comme commandé
    Route::post('/shopping-list/{id}/mark-received', [ShoppingListController::class, 'markAsReceived']); // Marquer comme reçu
    Route::post('/shopping-list/{id}/cancel', [ShoppingListController::class, 'cancel']); // Annuler
    
    // Routes pour les items de la liste de courses
    Route::apiResource('shopping-list-items', ShoppingListItemController::class);
    Route::post('/shopping-list-items/{id}/mark-purchased', [ShoppingListItemController::class, 'markAsPurchased']); // Marquer comme acheté
});

// Routes pour le planning et le pointage
Route::middleware('auth:sanctum')->group(function () {
    // Routes pour les plannings
    Route::get('/schedules/weekly', [ScheduleController::class, 'getWeeklySchedule']); // Planning hebdomadaire
    Route::get('/schedules/monthly', [ScheduleController::class, 'getMonthlySchedule']); // Planning mensuel
    Route::post('/schedules/replicate-week', [ScheduleController::class, 'replicateWeek']); // Reconduire une semaine vers une autre
    Route::put('/schedules/{id}/validate-request', [ScheduleController::class, 'validateRequest']); // Valider un planning "request"
    Route::post('/schedules/publish', [ScheduleController::class, 'publishSchedule']); // Publier et envoyer le planning par email
    Route::apiResource('schedules', ScheduleController::class);

    // Demandes de remplacement (employé : créer ; admin : lister, accepter, rejeter)
    Route::post('/schedules/{scheduleId}/replacement-request', [ScheduleReplacementController::class, 'store']);
    Route::get('/replacement-requests', [ScheduleReplacementController::class, 'index']);
    Route::get('/replacement-requests/by-schedule-ids', [ScheduleReplacementController::class, 'getByScheduleIds']);
    Route::put('/replacement-requests/{id}/respond', [ScheduleReplacementController::class, 'respond']);

    // Absences (programmé mais pas pointé)
    Route::get('/absences/computed', [AbsenceController::class, 'computed']);
    Route::get('/absences', [AbsenceController::class, 'index']);
    Route::put('/absences/{id}', [AbsenceController::class, 'update']);
    Route::delete('/absences/{id}', [AbsenceController::class, 'destroy']);
    
    // Routes pour les pointages (doivent être définies AVANT apiResource)
    Route::post('/time-entries/send-clock-in-code', [TimeEntryController::class, 'sendClockInCode']); // Envoyer code de vérification
    Route::post('/time-entries/clock-in-with-code', [TimeEntryController::class, 'clockInWithCode']); // Pointer avec code
    Route::post('/time-entries/clock-in-with-photo', [TimeEntryController::class, 'clockInWithPhoto']); // Pointer avec photo
    Route::post('/time-entries/clock-out-for-user', [TimeEntryController::class, 'clockOutForUser']); // Pointer départ pour un utilisateur
    Route::post('/time-entries/clock-in', [TimeEntryController::class, 'clockIn']); // Pointer l'arrivée
    Route::post('/time-entries/clock-out', [TimeEntryController::class, 'clockOut']); // Pointer le départ
    Route::post('/time-entries/create-manual', [TimeEntryController::class, 'createManual']); // Créer un pointage manuel
    Route::post('/time-entries/check-overtime', [TimeEntryController::class, 'checkOvertime']); // Vérifier et clôturer les pointages qui dépassent la limite heures sup
    Route::get('/time-entries/today', [TimeEntryController::class, 'getTodayEntry']); // Pointage du jour
    Route::get('/time-entries/user/{userId}', [TimeEntryController::class, 'getUserEntries']); // Historique des pointages
    Route::get('/time-entries/statistics', [TimeEntryController::class, 'getStatistics']); // Statistiques
    Route::apiResource('time-entries', TimeEntryController::class);
    
    // Routes pour les corrections de pointage
    Route::apiResource('time-entry-corrections', TimeEntryCorrectionController::class);
    
    // Routes pour les pauses
    Route::post('/breaks/start', [BreakController::class, 'startBreak']); // Démarrer une pause
    Route::post('/breaks/end', [BreakController::class, 'endBreak']); // Terminer une pause
    Route::get('/breaks/time-entry/{timeEntryId}', [BreakController::class, 'getBreaks']); // Obtenir les pauses d'un time entry
    Route::put('/breaks/{id}', [BreakController::class, 'updateBreak']); // Corriger horaires d'une pause (admin / time_entry)
    Route::delete('/breaks/{id}', [BreakController::class, 'deleteBreak']); // Supprimer une pause (admin / time_entry)
    
    // Routes pour la distribution des rapports de paie (admin uniquement)
    Route::post('/payroll-reports/distribute', [PayrollReportController::class, 'distribute']); // Distribuer les rapports
    Route::get('/payroll-reports/statuses', [PayrollReportController::class, 'getStatuses']); // Récupérer les statuts des rapports
    Route::get('/payroll-reports/decided-unseen-count', [PayrollReportController::class, 'getDecidedUnseenCount']); // Badge : nombre de rapports confirmés/rejetés non vus
    Route::post('/payroll-reports/mark-decided-seen', [PayrollReportController::class, 'markDecidedSeen']); // Marquer les rapports comme vus par l'admin
    
    // Routes pour les tâches
    Route::get('/tasks/my', [TaskController::class, 'myTasks']); // Mes tâches
    Route::get('/tasks/my-count', [TaskController::class, 'myTasksCount']); // Badge : nombre de tâches à faire
    Route::apiResource('tasks', TaskController::class);
    
    // Routes pour les super tâches
    Route::get('/super-tasks/my', [SuperTaskController::class, 'mySuperTasks']); // Mes super tâches
    Route::get('/super-tasks/has-pending', [SuperTaskController::class, 'hasPendingSuperTasks']); // Vérifier si l'utilisateur a des super tâches en attente
    Route::post('/super-tasks/schedule', [SuperTaskController::class, 'schedule']); // Programmer sur X mois (jour + durée)
    Route::apiResource('super-tasks', SuperTaskController::class);
    
    // Routes pour les congés
    Route::get('/leaves/my', [LeaveController::class, 'myLeaves']); // Mes congés
    Route::get('/leaves/my-decided-unseen-count', [LeaveController::class, 'myDecidedUnseenCount']); // Badge : nombre de réponses non vues
    Route::post('/leaves/mark-decided-seen', [LeaveController::class, 'markDecidedSeen']); // Marquer les réponses comme vues
    Route::get('/leaves/pending', [LeaveController::class, 'pending']); // Demandes en attente (admin)
    Route::post('/leaves/{id}/approve', [LeaveController::class, 'approve']); // Approuver un congé (admin)
    Route::post('/leaves/{id}/reject', [LeaveController::class, 'reject']); // Rejeter un congé (admin)
    Route::apiResource('leaves', LeaveController::class);
});

// Routes publiques pour les rapports de paie (accessible via token)
Route::get('/payroll-reports/token/{token}', [PayrollReportController::class, 'getByToken']); // Récupérer le rapport via token
Route::post('/payroll-reports/token/{token}/confirm', [PayrollReportController::class, 'confirm']); // Confirmer le rapport
Route::post('/payroll-reports/token/{token}/reject', [PayrollReportController::class, 'reject']); // Rejeter le rapport
