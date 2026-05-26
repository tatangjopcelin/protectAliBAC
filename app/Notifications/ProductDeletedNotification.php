<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductDeletedNotification extends Notification
{
    use Queueable;

    protected $product;
    protected $user;

    /**
     * Create a new notification instance.
     */
    public function __construct(Product $product, User $user)
    {
        $this->product = $product;
        $this->user = $user;
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
        
        return (new MailMessage)
            ->subject('🗑️ Produit désactivé - ' . $this->product->name . ' - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->user->name . ' a désactivé le produit "' . $this->product->name . '".')
            ->line('**Détails du produit désactivé :**')
            ->line('• Produit : ' . $this->product->name)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Zone : ' . $zoneName)
            ->line('• Stock au moment de la désactivation : ' . $this->product->quantity . ' ' . $this->product->unit)
            ->line('• Date d\'expiration : ' . \Carbon\Carbon::parse($this->product->expiration_date)->format('d/m/Y'))
            ->line('• Statut : Désactivé (non visible dans la liste active)')
            ->line('Désactivé le : ' . now()->format('d/m/Y à H:i'))
            ->salutation('Cordialement, L\'équipe ' . $notifiable->getMailSignatureName());
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
        ];
    }
}
