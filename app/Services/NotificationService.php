<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\PushToken;
use App\Models\User;
use App\Models\Product;
use App\Models\Alert;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        private readonly ApnService $apnService,
        private readonly FcmService $fcmService,
        private readonly SmsService $smsService
    ) {}

    /**
     * Envoie une notification selon les préférences de l'utilisateur
     */
    public function sendNotification(User $user, string $channel, string $title, string $message, array $data = [], string $type = 'push'): bool
    {
        // Canal réservé aux admins : ne rien envoyer ni créer pour un non-admin (ex. cuisinier, directeur)
        if ($channel === 'super_admin_broadcast' && !in_array($user->role, ['admin', 'super_admin'], true)) {
            Log::info('Notification super_admin_broadcast ignorée pour un non-admin', [
                'user_id' => $user->id,
                'role' => $user->role,
            ]);
            return false;
        }

        // Toujours créer une notification en base de données (avec store_id pour multi-établissements)
        try {
            Notification::create([
                'user_id' => $user->id,
                'store_id' => $user->store_id,
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
                'sms_enabled' => in_array($channel, [
                    'payroll_report',
                    'schedule_published',
                    'expiration',
                    'expired',
                    'task_due_today',
                    'super_task_due_today',
                    'super_task_assigned',
                    'super_task_missing',
                    'task_overdue',
                    'super_task_overdue',
                    'support_ticket_new',
                    'support_ticket_reply',
                    'super_admin_broadcast',
                ], true),
                'whatsapp_enabled' => false,
                'severity_level' => 'all'
            ];
        }

        $sent = false;

        // Notification Push : uniquement pour admin et super_admin (les employés ne reçoivent pas de push)
        if (in_array($user->role, ['admin', 'super_admin'], true) && $preference->push_enabled && in_array($type, ['push', 'all'])) {
            $sent = $this->sendPushNotification($user, $title, $message, $data) || $sent;
        }

        // Notification Email (sauf canaux où un email dédié est déjà envoyé)
        $skipEmailChannels = ['payroll_report', 'schedule_published', 'expiration', 'expired', 'task_due_today', 'super_task_assigned', 'super_task_missing'];
        if ($preference->email_enabled && in_array($type, ['email', 'all']) && !in_array($channel, $skipEmailChannels, true)) {
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
     * Envoie une notification push aux appareils enregistrés (iOS via APNs, Android via FCM).
     */
    private function sendPushNotification(User $user, string $title, string $message, array $data = []): bool
    {
        $tokens = PushToken::where('user_id', $user->id)->get();
        if ($tokens->isEmpty()) {
            return false;
        }
        $sent = false;
        foreach ($tokens as $pushToken) {
            try {
                if ($pushToken->platform === 'ios') {
                    if ($this->apnService->send($pushToken->token, $title, $message, $data)) {
                        $sent = true;
                    }
                } elseif ($pushToken->platform === 'android' && $this->fcmService->isConfigured()) {
                    if ($this->fcmService->send($pushToken->token, $title, $message, $data)) {
                        $sent = true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Envoi push échoué', ['user_id' => $user->id, 'platform' => $pushToken->platform, 'error' => $e->getMessage()]);
            }
        }
        return $sent;
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
     * Envoie une notification SMS (Twilio, France).
     * Le numéro de l'utilisateur (User::phone) doit être renseigné (ex. 06 12 34 56 78).
     */
    private function sendSMSNotification(User $user, string $message): bool
    {
        if (! $this->smsService->isConfigured()) {
            return false;
        }

        $to = $this->smsService->toE164France($user->phone ?? '');
        if ($to === null) {
            Log::debug('SMS non envoyé: numéro manquant ou invalide pour la France', ['user_id' => $user->id]);

            return false;
        }

        return $this->smsService->send($to, $message);
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
        if (!$product->relationLoaded('category')) {
            $product->load('category');
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

        // Pour les alertes de péremption, limiter au Chef, Directeur (et Admin)
        if ($channel === 'expiration' || $channel === 'expired') {
            $query->whereIn('role', ['chef', 'director', 'admin']);
        }

        $users = $query->get();

        foreach ($users as $user) {
            // Pour les alertes de péremption, utiliser une notification Mailable dédiée + push court
            if ($channel === 'expiration' || $channel === 'expired') {
                try {
                    $user->notify(new \App\Notifications\ProductExpirationAlertNotification($alert, $product));
                } catch (\Exception $e) {
                    \Log::error('Erreur envoi notification email péremption: ' . $e->getMessage());
                    // En cas d'erreur, fallback sur la méthode standard
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
                        'all' // Toujours envoyer par email pour les alertes de péremption
                    );
                }
                // Push + SMS : message court (titre + corps adaptés mobile)
                $pushTitle = 'Péremption';
                $pushBody = $this->getShortExpirationPushMessage($product, $channel);
                $data = [
                    'channel' => $channel,
                    'product_id' => (string) $product->id,
                    'alert_id' => (string) $alert->id,
                    'route' => '/tabs/alerts',
                    'screen' => 'alerts',
                ];
                $this->sendNotification($user, $channel, $pushTitle, $pushBody, $data, 'all');
            } else {
                // Pour les autres types d'alertes, utiliser la méthode standard
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
     * Message push/SMS pour alerte péremption : "Le produit [nom] stocké dans la zone [zone] expire..."
     */
    private function getShortExpirationPushMessage(Product $product, string $channel): string
    {
        if (! $product->relationLoaded('zone')) {
            $product->load('zone');
        }
        $name = $product->name;
        $zoneName = $product->zone?->name;
        $zonePart = $zoneName !== null && $zoneName !== '' ? " stocké dans la zone {$zoneName}" : '';
        $prefix = "Le produit {$name}{$zonePart}";

        if ($channel === 'expired') {
            return "{$prefix} est périmé.";
        }
        if (! $product->expiration_date) {
            return "{$prefix} : alerte date.";
        }
        $exp = \Carbon\Carbon::parse($product->expiration_date)->startOfDay();
        $today = \Carbon\Carbon::today();
        $days = $today->diffInDays($exp, false);
        if ($days === 0) {
            return "{$prefix} expire aujourd'hui.";
        }
        if ($days === 1) {
            return "{$prefix} expire demain.";
        }
        if ($days > 0 && $days <= 7) {
            $jours = $days === 1 ? '1 jour' : "{$days} jours";
            return "{$prefix} expire dans {$jours}.";
        }
        return "{$prefix} : à surveiller.";
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


