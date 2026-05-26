<?php

namespace App\Notifications;

use App\Models\SuperTask;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuperTaskAssignedNotification extends Notification
{
    use Queueable;

    protected $superTask;
    protected $creator;

    /**
     * Create a new notification instance.
     */
    public function __construct(SuperTask $superTask, User $creator)
    {
        $this->superTask = $superTask;
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
        $typeLabels = [
            'friteuse' => 'Friteuse',
            'chambre_froide' => 'Chambre froide'
        ];
        
        $type = $typeLabels[$this->superTask->type] ?? $this->superTask->type;
        $weekStart = \Carbon\Carbon::parse($this->superTask->week_start_date)->format('d/m/Y');
        $weekEnd = \Carbon\Carbon::parse($this->superTask->week_start_date)->endOfWeek()->format('d/m/Y');
        
        $mail = (new MailMessage)
            ->subject('Super tâche assignée - ' . $type . ' - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->creator->name . ' vous a assigné une super tâche importante pour cette semaine.')
            ->line('**Détails de la super tâche :**')
            ->line('• Type : ' . $type)
            ->line('• Semaine : Du ' . $weekStart . ' au ' . $weekEnd);
        
        if ($this->superTask->type === 'friteuse') {
            $mail->line('**Actions à effectuer :**')
                ->line('• Changement d\'huile')
                ->line('• Nettoyage de la friteuse');
        } else {
            $mail->line('**Actions à effectuer :**')
                ->line('• Nettoyage de la chambre froide')
                ->line('• Organisation de la chambre froide');
        }
        
        $mail->line('Assignée le : ' . $this->superTask->created_at->format('d/m/Y à H:i'))
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
            'super_task_id' => $this->superTask->id,
            'type' => $this->superTask->type,
            'week_start_date' => $this->superTask->week_start_date->format('Y-m-d'),
            'creator_id' => $this->creator->id,
            'creator_name' => $this->creator->name,
        ];
    }
}
