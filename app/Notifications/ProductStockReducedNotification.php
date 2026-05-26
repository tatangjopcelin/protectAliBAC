<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductStockReducedNotification extends Notification
{
    use Queueable;

    protected $product;
    protected $user;
    protected $quantityReduced;
    protected $oldQuantity;
    protected $newQuantity;
    protected $reason;
    protected $movementType;

    /**
     * Create a new notification instance.
     */
    public function __construct(Product $product, User $user, $quantityReduced, $oldQuantity, $newQuantity, $reason = null, $movementType = null)
    {
        $this->product = $product;
        $this->user = $user;
        $this->quantityReduced = $quantityReduced;
        $this->oldQuantity = $oldQuantity;
        $this->newQuantity = $newQuantity;
        $this->reason = $reason;
        $this->movementType = $movementType;
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
        
        $typeLabels = [
            'used' => 'Utilisé',
            'wasted' => 'Gaspillé',
            'exit' => 'Sortie',
            'transformed' => 'Transformé'
        ];
        $typeLabel = $typeLabels[$this->movementType] ?? $this->movementType ?? 'Réduction';
        
        $mail = (new MailMessage)
            ->subject('Stock réduit - ' . $this->product->name . ' - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->user->name . ' a réduit le stock du produit "' . $this->product->name . '".')
            ->line('**Détails de la modification :**')
            ->line('• Produit : ' . $this->product->name)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Zone : ' . $zoneName)
            ->line('• Type : ' . $typeLabel)
            ->line('• Quantité retirée : **-' . $this->quantityReduced . ' ' . $this->product->unit . '**')
            ->line('• Stock précédent : ' . $this->oldQuantity . ' ' . $this->product->unit)
            ->line('• Nouveau stock : **' . $this->newQuantity . ' ' . $this->product->unit . '**');
        
        if ($this->reason) {
            $mail->line('• Raison : ' . $this->reason);
        }
        
        $mail->line('• Date d\'expiration : ' . \Carbon\Carbon::parse($this->product->expiration_date)->format('d/m/Y'))
            ->line('Modifié le : ' . now()->format('d/m/Y à H:i'))
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
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'quantity_reduced' => $this->quantityReduced,
            'old_quantity' => $this->oldQuantity,
            'new_quantity' => $this->newQuantity,
            'reason' => $this->reason,
            'movement_type' => $this->movementType,
        ];
    }
}
