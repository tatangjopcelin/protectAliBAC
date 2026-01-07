<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Alert;
use App\Services\NotificationService;
use Carbon\Carbon;

class AlertService
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService = null)
    {
        $this->notificationService = $notificationService ?? app(NotificationService::class);
    }
    /**
     * Vérifie et crée les alertes pour tous les produits
     */
    public function checkAllProducts(): void
    {
        $products = Product::with(['zone.store'])->where('is_active', true)->get();
        
        foreach ($products as $product) {
            $this->checkProduct($product);
        }
    }

    /**
     * Vérifie et crée les alertes pour un produit spécifique
     */
    public function checkProduct(Product $product): void
    {
        // Charger les relations nécessaires si pas déjà chargées
        if (!$product->relationLoaded('zone')) {
            $product->load('zone.store');
        }
        
        // Vérifier la péremption
        $this->checkExpiration($product);
        
        // Vérifier le stock bas
        $this->checkLowStock($product);
    }

    /**
     * Vérifie les dates de péremption et crée des alertes
     */
    private function checkExpiration(Product $product): void
    {
        $today = Carbon::today();
        $expirationDate = Carbon::parse($product->expiration_date);
        $daysUntilExpiration = $today->diffInDays($expirationDate, false);
        
        $location = $this->getProductLocation($product);

        // Produit périmé
        if ($expirationDate->isPast()) {
            $message = "Le produit {$product->name} est périmé depuis " . abs($daysUntilExpiration) . " jour(s). {$location}";
            $this->createAlert($product, 'expired', 'critical', $message);
            $product->updateStatus();
        }
        // Produit expire aujourd'hui ou demain
        elseif ($daysUntilExpiration <= 1) {
            $timeText = $daysUntilExpiration == 0 ? "aujourd'hui" : "demain";
            $message = "Le produit {$product->name} expire {$timeText}. {$location}";
            $this->createAlert($product, 'expiration', 'critical', $message);
            $product->updateStatus();
        }
        // Produit expire dans 3 jours
        elseif ($daysUntilExpiration <= 3) {
            $message = "Le produit {$product->name} expire dans {$daysUntilExpiration} jour(s). {$location}";
            $this->createAlert($product, 'expiration', 'warning', $message);
            $product->updateStatus();
        }
        // Produit expire dans 7 jours (notification préventive)
        elseif ($daysUntilExpiration <= 7 && $daysUntilExpiration > 3) {
            $message = "Le produit {$product->name} expire dans {$daysUntilExpiration} jour(s) - À surveiller. {$location}";
            $this->createAlert($product, 'expiration', 'info', $message);
            $product->updateStatus();
        }
    }

    /**
     * Vérifie le stock bas
     */
    private function checkLowStock(Product $product): void
    {
        if ($product->isLowStock()) {
            $location = $this->getProductLocation($product);
            $message = "Stock bas pour {$product->name} : {$product->quantity} {$product->unit} restant(s). {$location}";
            $this->createAlert($product, 'low_stock', 'warning', $message);
        }
    }

    /**
     * Récupère la localisation du produit (magasin et zone)
     */
    private function getProductLocation(Product $product): string
    {
        $locationParts = [];
        
        if ($product->zone) {
            $locationParts[] = "Zone: {$product->zone->name}";
            
            // Ajouter étagère et bac si disponibles
            if ($product->zone->shelf) {
                $locationParts[] = "Étagère: {$product->zone->shelf}";
            }
            if ($product->zone->bin) {
                $locationParts[] = "Bac: {$product->zone->bin}";
            }
            
            // Ajouter le magasin si disponible
            if ($product->zone->store) {
                $locationParts[] = "Magasin: {$product->zone->store->name}";
            }
        }
        
        if (empty($locationParts)) {
            return "Localisation non définie";
        }
        
        return "Stocké dans " . implode(", ", $locationParts);
    }

    /**
     * Crée une alerte si elle n'existe pas déjà
     */
    private function createAlert(Product $product, string $type, string $severity, string $message): void
    {
        // Récupérer le store_id du produit via sa zone
        $storeId = $product->zone?->store_id;
        if (!$storeId) {
            // Si le produit n'a pas de zone ou la zone n'a pas de store_id, on ne peut pas créer l'alerte
            \Log::warning("Impossible de créer une alerte pour le produit {$product->id} : pas de store_id associé");
            return;
        }

        // Pour les alertes de péremption, vérifier s'il existe déjà une alerte non lue
        // et la mettre à jour plutôt que d'en créer une nouvelle
        if ($type === 'expiration' || $type === 'expired') {
            $existingAlert = Alert::where('product_id', $product->id)
                ->where('store_id', $storeId) // Filtrer par store_id pour la sécurité
                ->whereIn('type', ['expiration', 'expired'])
                ->where('is_read', false)
                ->first();

            if ($existingAlert) {
                // Mettre à jour l'alerte existante avec le nouveau message et sévérité
                $existingAlert->update([
                    'type' => $type,
                    'message' => $message,
                    'severity' => $severity,
                ]);
                return;
            }
        } else {
            // Pour les autres types d'alertes, vérifier s'il existe une alerte non lue du même type
            $existingAlert = Alert::where('product_id', $product->id)
                ->where('store_id', $storeId) // Filtrer par store_id pour la sécurité
                ->where('type', $type)
                ->where('is_read', false)
                ->first();

            if ($existingAlert) {
                // Mettre à jour le message si nécessaire
                $existingAlert->update([
                    'message' => $message,
                    'severity' => $severity,
                ]);
                return;
            }
        }

        // Créer une nouvelle alerte avec le store_id
        $alert = Alert::create([
            'product_id' => $product->id,
            'store_id' => $storeId, // Ajouter le store_id pour la sécurité multi-establishment
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
        ]);

        // Charger les relations nécessaires pour la notification
        $alert->load('product.zone.store', 'product.category');

        // Envoyer les notifications selon les préférences des utilisateurs
        try {
            $this->notificationService->notifyAlert($alert, $severity);
        } catch (\Exception $e) {
            // Logger l'erreur mais ne pas bloquer la création de l'alerte
            \Log::error('Erreur lors de l\'envoi de notification: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Nettoie les anciennes alertes lues
     */
    public function cleanOldAlerts(int $days = 30): void
    {
        Alert::where('is_read', true)
            ->where('read_at', '<', Carbon::now()->subDays($days))
            ->delete();
    }
}

