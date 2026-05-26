<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
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
        $verificationCode = $notifiable->email_verification_code;

        return (new MailMessage)
            ->subject('Code de vérification - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line('Merci de vous être inscrit sur ' . config('app.name') . '.')
            ->line('Votre code de vérification est :')
            ->line('**' . $verificationCode . '**')
            ->line('Entrez ce code dans l\'application pour vérifier votre adresse email et activer votre compte.')
            ->line('Ce code est valide pendant 15 minutes.')
            ->line('Si vous n\'avez pas créé de compte, vous pouvez ignorer cet email.')
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
            //
        ];
    }
}
