<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductUpdatedNotification extends Notification
{
    use Queueable;

    protected $product;
    protected $updater;

    /**
     * Create a new notification instance.
     */
    public function __construct(Product $product, User $updater)
    {
        $this->product = $product;
        $this->updater = $updater;
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
            ->subject('Produit modifié - Table du Boucher')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->updater->name . ' a modifié le produit "' . $this->product->name . '".')
            ->line('**Détails du produit :**')
            ->line('• Nom : ' . $this->product->name)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Zone : ' . $zoneName)
            ->line('• Quantité : ' . $this->product->quantity . ' ' . $this->product->unit)
            ->line('• Date d\'expiration : ' . \Carbon\Carbon::parse($this->product->expiration_date)->format('d/m/Y'))
            ->line('Modifié le : ' . $this->product->updated_at->format('d/m/Y à H:i'))
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
            'updater_id' => $this->updater->id,
            'updater_name' => $this->updater->name,
        ];
    }
}
