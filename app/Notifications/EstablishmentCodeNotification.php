<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EstablishmentCodeNotification extends Notification
{
    use Queueable;

    protected $storeName;
    protected $establishmentCode;

    /**
     * Create a new notification instance.
     */
    public function __construct($storeName, $establishmentCode)
    {
        $this->storeName = $storeName;
        $this->establishmentCode = $establishmentCode;
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
            ->subject('Code d\'établissement - Table du Boucher')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line('Votre établissement "' . $this->storeName . '" a été créé avec succès.')
            ->line('Votre code d\'établissement est :')
            ->line('**' . $this->establishmentCode . '**')
            ->line('Partagez ce code à vos employés pour qu\'ils puissent créer leur compte et rejoindre votre établissement.')
            ->line('Important : Gardez ce code secret et partagez-le uniquement avec vos employés de confiance.')
            ->salutation('Cordialement, L\'équipe ' . $this->storeName);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'store_name' => $this->storeName,
            'establishment_code' => $this->establishmentCode,
        ];
    }
}
