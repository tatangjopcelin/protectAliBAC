<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class ProductExpirationAlertNotification extends Notification
{
    use Queueable;

    protected $alert;
    protected $product;

    /**
     * Create a new notification instance.
     */
    public function __construct(Alert $alert, Product $product)
    {
        $this->alert = $alert;
        $this->product = $product;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $zoneName = $this->product->zone ? $this->product->zone->name : 'Non spécifiée';
        $categoryName = $this->product->category ? $this->product->category->name : 'Non spécifiée';
        $storeName = $this->product->zone?->store?->name ?? 'Non spécifiée';
        
        $expirationDate = $this->product->expiration_date 
            ? Carbon::parse($this->product->expiration_date)->format('d/m/Y')
            : 'Non définie';
        
        $today = Carbon::today();
        $expirationDateObj = $this->product->expiration_date ? Carbon::parse($this->product->expiration_date) : null;
        $daysUntilExpiration = $expirationDateObj ? $today->diffInDays($expirationDateObj, false) : null;
        
        // Déterminer le niveau d'urgence
        $urgencyLevel = '';
        $urgencyIcon = '';
        if ($this->alert->severity === 'critical') {
            if ($this->alert->type === 'expired') {
                $urgencyLevel = 'URGENT - Produit périmé';
                $urgencyIcon = '🔴';
            } else {
                $urgencyLevel = 'URGENT - Expire bientôt';
                $urgencyIcon = '🟠';
            }
        } elseif ($this->alert->severity === 'warning') {
            $urgencyLevel = 'Attention requise';
            $urgencyIcon = '🟡';
        } else {
            $urgencyLevel = 'À surveiller';
            $urgencyIcon = '🔵';
        }
        
        $mail = (new MailMessage)
            ->subject($urgencyIcon . ' Alerte péremption - ' . $this->product->name . ' - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line('**' . $urgencyLevel . '**')
            ->line($this->alert->message);
        
        $mail->line('**Détails du produit :**')
            ->line('• Produit : ' . $this->product->name)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Zone : ' . $zoneName)
            ->line('• Établissement : ' . $storeName)
            ->line('• Quantité en stock : **' . $this->product->quantity . ' ' . $this->product->unit . '**')
            ->line('• Date de péremption : **' . $expirationDate . '**');
        
        if ($daysUntilExpiration !== null) {
            if ($daysUntilExpiration < 0) {
                $mail->line('• Statut : ⚠️ **Périmé depuis ' . abs($daysUntilExpiration) . ' jour(s)**');
            } elseif ($daysUntilExpiration == 0) {
                $mail->line('• Statut : ⚠️ **Expire aujourd\'hui**');
            } elseif ($daysUntilExpiration == 1) {
                $mail->line('• Statut : ⚠️ **Expire demain**');
            } else {
                $mail->line('• Statut : Expire dans **' . $daysUntilExpiration . ' jour(s)**');
            }
        }
        
        $mail->line('')
            ->line('Veuillez vérifier ce produit et prendre les mesures nécessaires.')
            ->salutation('Cordialement, L\'équipe ' . $notifiable->getMailSignatureName());
        
        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'alert_type' => $this->alert->type,
            'severity' => $this->alert->severity,
            'message' => $this->alert->message,
        ];
    }
}

