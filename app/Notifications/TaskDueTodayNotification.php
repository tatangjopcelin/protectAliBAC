<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskDueTodayNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Task $task
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $priorityLabels = [
            'urgent' => 'Urgente',
            'high' => 'Haute',
            'medium' => 'Moyenne',
            'low' => 'Basse',
        ];
        $priority = $priorityLabels[$this->task->priority] ?? $this->task->priority;

        $mail = (new MailMessage)
            ->subject('Tâche à effectuer aujourd\'hui - ' . config('app.name'))
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line('Une tâche vous est assignée pour **aujourd\'hui**.')
            ->line('**' . $this->task->title . '**')
            ->line('Priorité : ' . $priority);

        if ($this->task->description) {
            $mail->line($this->task->description);
        }

        if ($this->task->notes) {
            $mail->line('Notes : ' . $this->task->notes);
        }

        $mail->salutation('Cordialement, L\'équipe ' . $notifiable->getMailSignatureName());

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'due_date' => $this->task->due_date?->format('Y-m-d'),
        ];
    }
}
