<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskCreatedNotification extends Notification
{
    use Queueable;

    protected $task;
    protected $creator;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task, User $creator)
    {
        $this->task = $task;
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
            'urgent' => 'Urgente',
            'high' => 'Haute',
            'medium' => 'Moyenne',
            'low' => 'Basse'
        ];
        
        $statusLabels = [
            'pending' => 'En attente',
            'in_progress' => 'En cours',
            'completed' => 'Terminée',
            'cancelled' => 'Annulée'
        ];
        
        $priority = $priorityLabels[$this->task->priority] ?? $this->task->priority;
        $status = $statusLabels[$this->task->status] ?? $this->task->status;
        
        $mail = (new MailMessage)
            ->subject('Nouvelle tâche assignée - Table du Boucher')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line($this->creator->name . ' vous a assigné une nouvelle tâche.')
            ->line('**Détails de la tâche :**')
            ->line('• Titre : ' . $this->task->title);
        
        if ($this->task->description) {
            $mail->line('• Description : ' . $this->task->description);
        }
        
        $mail->line('• Priorité : ' . $priority)
            ->line('• Statut : ' . $status);
        
        if ($this->task->due_date) {
            $mail->line('• Date d\'échéance : ' . $this->task->due_date->format('d/m/Y'));
        }
        
        if ($this->task->notes) {
            $mail->line('• Notes : ' . $this->task->notes);
        }
        
        $mail->line('Assignée le : ' . $this->task->created_at->format('d/m/Y à H:i'))
            ->salutation('Cordialement, L\'équipe Table du Boucher');
        
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
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'creator_id' => $this->creator->id,
            'creator_name' => $this->creator->name,
            'priority' => $this->task->priority,
            'status' => $this->task->status,
            'due_date' => $this->task->due_date?->format('Y-m-d'),
        ];
    }
}

