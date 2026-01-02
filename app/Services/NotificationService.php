<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Product;
use App\Models\Alert;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Envoie une notification selon les préférences de l'utilisateur
     */
    public function sendNotification(User $user, string $channel, string $title, string $message, array $data = [], string $type = 'push'): bool
    {
        // Toujours créer une notification en base de données
        try {
            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'channel' => $channel,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur création notification: ' . $e->getMessage());
        }

        $preference = NotificationPreference::where('user_id', $user->id)
            ->where('channel', $channel)
            ->first();

        // Si pas de préférence, utiliser les valeurs par défaut
        if (!$preference) {
            $preference = (object)[
                'push_enabled' => true,
                'email_enabled' => true,
                'sms_enabled' => false,
                'whatsapp_enabled' => false,
                'severity_level' => 'all'
            ];
        }

        $sent = false;

        // Notification Push
        if ($preference->push_enabled && in_array($type, ['push', 'all'])) {
            $sent = $this->sendPushNotification($user, $title, $message, $data) || $sent;
        }

        // Notification Email
        if ($preference->email_enabled && in_array($type, ['email', 'all'])) {
            $sent = $this->sendEmailNotification($user, $title, $message, $data) || $sent;
        }

        // Notification SMS
        if ($preference->sms_enabled && in_array($type, ['sms', 'all'])) {
            $sent = $this->sendSMSNotification($user, $message) || $sent;
        }

        // Notification WhatsApp
        if ($preference->whatsapp_enabled && in_array($type, ['whatsapp', 'all'])) {
            $sent = $this->sendWhatsAppNotification($user, $message) || $sent;
        }

        return $sent;
    }

    /**
     * Envoie une notification push (à implémenter avec un service externe)
     */
    private function sendPushNotification(User $user, string $title, string $message, array $data = []): bool
    {
        // La notification est déjà créée dans sendNotification
        // Ici on peut ajouter l'intégration avec un service de push externe
        // TODO: Intégrer avec un service de push (Firebase, Pusher, etc.)
        return true;
    }

    /**
     * Envoie une notification email
     */
    private function sendEmailNotification(User $user, string $title, string $message, array $data = []): bool
    {
        try {
            // Envoyer l'email
            Mail::raw($message, function ($mail) use ($user, $title) {
                $mail->to($user->email)
                    ->subject($title);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Erreur envoi email notification: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envoie une notification SMS (à implémenter avec un service externe)
     */
    private function sendSMSNotification(User $user, string $message): bool
    {
        // La notification est déjà créée dans sendNotification
        // TODO: Intégrer avec un service SMS (Twilio, etc.)
        return true;
    }

    /**
     * Envoie une notification WhatsApp (à implémenter avec une API)
     */
    private function sendWhatsAppNotification(User $user, string $message): bool
    {
        // La notification est déjà créée dans sendNotification
        // TODO: Intégrer avec WhatsApp Business API
        return true;
    }

    /**
     * Notifie tous les utilisateurs concernés par une alerte
     */
    public function notifyAlert(Alert $alert, string $severity = 'warning'): void
    {
        $product = $alert->product;
        $channel = $alert->type; // 'expiration', 'low_stock', 'expired'

        // Charger les relations nécessaires
        if (!$product->relationLoaded('zone')) {
            $product->load('zone.store');
        }

        // Construire le message avec toutes les informations
        $location = $this->getProductLocation($product);
        $expirationInfo = $product->expiration_date 
            ? "Date de péremption: " . \Carbon\Carbon::parse($product->expiration_date)->format('d/m/Y')
            : "Date de péremption non définie";
        
        $fullMessage = "{$alert->message}\n\n{$location}\n{$expirationInfo}";

        // Notifier seulement les utilisateurs de l'établissement concerné
        $storeId = $product->zone?->store_id;
        $query = User::whereNotNull('email_verified_at');
        
        if ($storeId) {
            $query->where('store_id', $storeId);
        }
        
        $users = $query->get();

        foreach ($users as $user) {
            $this->sendNotification(
                $user,
                $channel,
                "Alerte: {$product->name}",
                $fullMessage,
                [
                    'channel' => $channel,
                    'product_id' => $product->id,
                    'alert_id' => $alert->id,
                    'severity' => $alert->severity,
                    'zone_id' => $product->zone_id,
                    'zone_name' => $product->zone?->name,
                    'expiration_date' => $product->expiration_date?->format('Y-m-d'),
                ],
                $severity === 'critical' ? 'all' : 'push'
            );
        }
    }

    /**
     * Notifie tous les utilisateurs lors de l'ajout d'un produit
     */
    public function notifyProductAdded(Product $product, User $addedBy): void
    {
        // Charger les relations nécessaires
        if (!$product->relationLoaded('zone')) {
            $product->load('zone.store');
        }

        $location = $this->getProductLocation($product);
        $expirationDate = $product->expiration_date 
            ? \Carbon\Carbon::parse($product->expiration_date)->format('d/m/Y')
            : "Non définie";
        
        $message = "Nouveau produit ajouté au stock:\n\n";
        $message .= "Produit: {$product->name}\n";
        $message .= "Quantité: {$product->quantity} {$product->unit}\n";
        $message .= "Date de péremption: {$expirationDate}\n";
        $message .= "Ajouté par: {$addedBy->name}\n";
        $message .= "{$location}";

        // Notifier seulement les utilisateurs de l'établissement concerné
        $storeId = $product->zone?->store_id;
        $query = User::whereNotNull('email_verified_at');
        
        if ($storeId) {
            $query->where('store_id', $storeId);
        }
        
        $users = $query->get();

        foreach ($users as $user) {
            $this->sendNotification(
                $user,
                'database',
                "Nouveau produit: {$product->name}",
                $message,
                [
                    'channel' => 'product_added',
                    'product_id' => $product->id,
                    'added_by_id' => $addedBy->id,
                    'added_by_name' => $addedBy->name,
                    'zone_id' => $product->zone_id,
                    'zone_name' => $product->zone?->name,
                    'quantity' => $product->quantity,
                    'unit' => $product->unit,
                    'expiration_date' => $product->expiration_date?->format('Y-m-d'),
                ],
                'push'
            );
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
}


