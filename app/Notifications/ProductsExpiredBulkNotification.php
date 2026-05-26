<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductsExpiredBulkNotification extends Notification
{
    use Queueable;

    protected $user;
    protected $processedCount;
    protected $totalFound;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $user, $processedCount, $totalFound)
    {
        $this->user = $user;
        $this->processedCount = $processedCount;
        $this->totalFound = $totalFound;
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
        return (new MailMessage)
            ->subject('⚠️ Traitement en masse des produits périmés - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->user->name . ' a effectué un traitement en masse des produits périmés.')
            ->line('**Résumé du traitement :**')
            ->line('• Produits trouvés : ' . $this->totalFound)
            ->line('• Produits traités : **' . $this->processedCount . '**')
            ->line('• Action : Stock réduit à 0 et statut changé en "périmé"')
            ->line('Traitement effectué le : ' . now()->format('d/m/Y à H:i'))
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
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'processed_count' => $this->processedCount,
            'total_found' => $this->totalFound,
        ];
    }
}
