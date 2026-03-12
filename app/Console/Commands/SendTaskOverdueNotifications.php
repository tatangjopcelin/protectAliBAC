<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendTaskOverdueNotifications extends Command
{
    protected $signature = 'tasks:notify-overdue';

    protected $description = 'Envoie une notification aux employés et aux managers pour les tâches en retard (date dépassée, non terminées).';

    public function handle(NotificationService $notificationService): int
    {
        $today = Carbon::today();

        $tasks = Task::with(['assignedTo', 'store'])
            ->whereDate('due_date', '<', $today->format('Y-m-d'))
            ->whereIn('status', ['pending', 'in_progress'])
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('Aucune tâche en retard.');
            return Command::SUCCESS;
        }

        $sent = 0;

        foreach ($tasks as $task) {
            $employee = $task->assignedTo;
            if (!$employee) {
                continue;
            }

            $dueDateLabel = Carbon::parse($task->due_date)->format('d/m/Y');

            // 1) Marquer la tâche comme absence (tâche non effectuée à temps)
            $task->status = 'absent';
            $task->save();

            // 2) Notification à l'employé
            if ($this->sendOverdueNotificationIfNotSent($notificationService, $employee, $task, $dueDateLabel, 'employee')) {
                $sent++;
            }

            // 3) Notification aux managers de l'établissement (admin, chef, directeur)
            $managers = User::where('store_id', $task->store_id)
                ->whereIn('role', ['admin', 'chef', 'director'])
                ->get();

            foreach ($managers as $manager) {
                if ($this->sendOverdueNotificationIfNotSent($notificationService, $manager, $task, $dueDateLabel, 'manager')) {
                    $sent++;
                }
            }
        }

        $this->info("Tâches en retard : {$tasks->count()} trouvée(s), {$sent} notification(s) envoyée(s).");
        return Command::SUCCESS;
    }

    /**
     * Envoie la notification "tâche en retard" à un utilisateur si elle n'a pas déjà été envoyée aujourd'hui.
     */
    private function sendOverdueNotificationIfNotSent(NotificationService $notificationService, User $user, Task $task, string $dueDateLabel, string $recipientType): bool
    {
        $today = Carbon::today()->format('Y-m-d');

        $alreadySent = Notification::where('user_id', $user->id)
            ->where('channel', 'task_overdue')
            ->where('data->task_id', (string) $task->id)
            ->where('data->recipient_type', $recipientType)
            ->whereDate('created_at', $today)
            ->exists();

        if ($alreadySent) {
            return false;
        }

        $title = 'Tâche en retard';
        $message = "La tâche « {$task->title} » est en retard (date prévue {$dueDateLabel}). Consultez l'app Brole.";

        $data = [
            'task_id' => (string) $task->id,
            'task_title' => $task->title,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'recipient_type' => $recipientType,
            'screen' => 'tasks',
            'route' => '/tabs/tasks',
        ];

        try {
            $notificationService->sendNotification(
                $user,
                'task_overdue',
                $title,
                $message,
                $data,
                'all'
            );
            Log::info('Notification tâche en retard envoyée', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'recipient_type' => $recipientType,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Erreur notification tâche en retard', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'recipient_type' => $recipientType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

