<?php

namespace App\Notifications;

use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShoppingListItemUpdatedNotification extends Notification
{
    use Queueable;

    protected $item;
    protected $updater;

    /**
     * Create a new notification instance.
     */
    public function __construct(ShoppingListItem $item, User $updater)
    {
        $this->item = $item;
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
        $priorityLabels = [
            'urgent' => 'Urgent',
            'high' => 'Élevée',
            'medium' => 'Moyenne',
            'low' => 'Faible'
        ];
        
        $statusLabels = [
            'pending' => 'En attente',
            'purchased' => 'Acheté',
            'cancelled' => 'Annulé'
        ];
        
        $priority = $priorityLabels[$this->item->priority] ?? $this->item->priority;
        $status = $statusLabels[$this->item->status] ?? $this->item->status;
        $categoryName = $this->item->category ? $this->item->category->name : 'Non spécifiée';
        
        return (new MailMessage)
            ->subject('Item de liste de courses modifié - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->updater->name . ' a modifié l\'item "' . $this->item->name . '" de la liste de courses.')
            ->line('**Détails de l\'item :**')
            ->line('• Nom : ' . $this->item->name)
            ->line('• Quantité : ' . $this->item->quantity . ' ' . $this->item->unit)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Priorité : ' . $priority)
            ->line('• Statut : ' . $status)
            ->when($this->item->notes, function ($mail) {
                return $mail->line('• Notes : ' . $this->item->notes);
            })
            ->line('Modifié le : ' . $this->item->updated_at->format('d/m/Y à H:i'))
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
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'updater_id' => $this->updater->id,
            'updater_name' => $this->updater->name,
        ];
    }
}
