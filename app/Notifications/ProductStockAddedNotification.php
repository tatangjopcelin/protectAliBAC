<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductStockAddedNotification extends Notification
{
    use Queueable;

    protected $product;
    protected $user;
    protected $quantityAdded;
    protected $oldQuantity;
    protected $newQuantity;

    /**
     * Create a new notification instance.
     */
    public function __construct(Product $product, User $user, $quantityAdded, $oldQuantity, $newQuantity)
    {
        $this->product = $product;
        $this->user = $user;
        $this->quantityAdded = $quantityAdded;
        $this->oldQuantity = $oldQuantity;
        $this->newQuantity = $newQuantity;
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
            ->subject('Stock ajouté - ' . $this->product->name . ' - Table du Boucher')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->user->name . ' a ajouté du stock au produit "' . $this->product->name . '".')
            ->line('**Détails de la modification :**')
            ->line('• Produit : ' . $this->product->name)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Zone : ' . $zoneName)
            ->line('• Quantité ajoutée : **+' . $this->quantityAdded . ' ' . $this->product->unit . '**')
            ->line('• Stock précédent : ' . $this->oldQuantity . ' ' . $this->product->unit)
            ->line('• Nouveau stock : **' . $this->newQuantity . ' ' . $this->product->unit . '**')
            ->line('• Date d\'expiration : ' . \Carbon\Carbon::parse($this->product->expiration_date)->format('d/m/Y'))
            ->line('Modifié le : ' . now()->format('d/m/Y à H:i'))
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
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'quantity_added' => $this->quantityAdded,
            'old_quantity' => $this->oldQuantity,
            'new_quantity' => $this->newQuantity,
        ];
    }
}
