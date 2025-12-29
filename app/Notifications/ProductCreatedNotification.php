<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductCreatedNotification extends Notification
{
    use Queueable;

    protected $product;
    protected $creator;

    /**
     * Create a new notification instance.
     */
    public function __construct(Product $product, User $creator)
    {
        $this->product = $product;
        $this->creator = $creator;
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
            ->subject('Nouveau produit créé - Table du Boucher')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->creator->name . ' a créé un nouveau produit.')
            ->line('**Détails du produit :**')
            ->line('• Nom : ' . $this->product->name)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Zone : ' . $zoneName)
            ->line('• Quantité : ' . $this->product->quantity . ' ' . $this->product->unit)
            ->line('• Date de réception : ' . \Carbon\Carbon::parse($this->product->reception_date)->format('d/m/Y'))
            ->line('• Date d\'expiration : ' . \Carbon\Carbon::parse($this->product->expiration_date)->format('d/m/Y'))
            ->line('Créé le : ' . $this->product->created_at->format('d/m/Y à H:i'))
            ->salutation('Cordialement, L\'équipe Table du Boucher');
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
            'creator_id' => $this->creator->id,
            'creator_name' => $this->creator->name,
        ];
    }
}
