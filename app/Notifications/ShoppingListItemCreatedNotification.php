<?php

namespace App\Notifications;

use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShoppingListItemCreatedNotification extends Notification
{
    use Queueable;

    protected $item;
    protected $creator;

    /**
     * Create a new notification instance.
     */
    public function __construct(ShoppingListItem $item, User $creator)
    {
        $this->item = $item;
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
        $priorityLabels = [
            'urgent' => 'Urgent',
            'high' => 'Élevée',
            'medium' => 'Moyenne',
            'low' => 'Faible'
        ];
        
        $priority = $priorityLabels[$this->item->priority] ?? $this->item->priority;
        $categoryName = $this->item->category ? $this->item->category->name : 'Non spécifiée';
        
        return (new MailMessage)
            ->subject('Nouvel item ajouté à la liste de courses - Table du Boucher')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->creator->name . ' a ajouté un nouvel item à la liste de courses.')
            ->line('**Détails de l\'item :**')
            ->line('• Nom : ' . $this->item->name)
            ->line('• Quantité : ' . $this->item->quantity . ' ' . $this->item->unit)
            ->line('• Catégorie : ' . $categoryName)
            ->line('• Priorité : ' . $priority)
            ->line('• Statut : En attente')
            ->when($this->item->notes, function ($mail) {
                return $mail->line('• Notes : ' . $this->item->notes);
            })
            ->line('Ajouté le : ' . $this->item->created_at->format('d/m/Y à H:i'))
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
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'creator_id' => $this->creator->id,
            'creator_name' => $this->creator->name,
        ];
    }
}
