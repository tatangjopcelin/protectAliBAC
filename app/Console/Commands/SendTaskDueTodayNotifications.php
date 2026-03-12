<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Task;
use App\Notifications\TaskDueTodayNotification;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendTaskDueTodayNotifications extends Command
{
    protected $signature = 'tasks:notify-due-today';

    protected $description = 'Envoie email, push et alerte in-app aux employés pour les tâches à effectuer aujourd\'hui';

    public function handle(NotificationService $notificationService): int
    {
        $today = Carbon::today()->format('Y-m-d');

        $tasks = Task::with(['assignedTo'])
            ->whereDate('due_date', $today)
            ->whereIn('status', ['pending', 'in_progress'])
            ->get();

        // Grouper par (employé, titre) pour éviter les doublons : une seule notification par titre et par personne par jour
        $grouped = [];
        foreach ($tasks as $task) {
            $user = $task->assignedTo;
            if (!$user) {
                continue;
            }
            $key = $user->id . '|' . $task->title;
            if (!isset($grouped[$key])) {
                $grouped[$key] = (object) ['user' => $user, 'task' => $task];
            }
        }

        $sent = 0;
        foreach ($grouped as $key => $item) {
            $user = $item->user;
            $task = $item->task;

            // Éviter d'envoyer deux fois la même journée (ex. si la commande est relancée)
            $alreadySent = Notification::where('user_id', $user->id)
                ->where('channel', 'task_due_today')
                ->where('data->task_title', $task->title)
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            try {
                // 1. Email (notification Laravel dédiée)
                if ($user->email_verified_at) {
                    $user->notify(new TaskDueTodayNotification($task));
                }

                // 2. Push + alerte in-app (enregistrement en base + push FCM/APNs)
                $title = 'Tâche à effectuer aujourd\'hui';
                $message = "La tâche « {$task->title} » est prévue pour aujourd'hui.";
                $data = [
                    'task_id' => (string) $task->id,
                    'task_title' => $task->title,
                    'due_date' => $today,
                    'screen' => 'tasks',
                    'route' => '/tabs/tasks',
                ];
                $notificationService->sendNotification(
                    $user,
                    'task_due_today',
                    $title,
                    $message,
                    $data,
                    'push'
                );

                $sent++;
                $this->line("  Notifié : {$user->name} – {$task->title}");
            } catch (\Throwable $e) {
                Log::error('Erreur notification tâche due aujourd\'hui', [
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  Échec pour tâche #{$task->id} : " . $e->getMessage());
            }
        }

        $this->info("Tâches dues aujourd'hui : {$tasks->count()} trouvée(s), {$sent} notification(s) envoyée(s).");
        return Command::SUCCESS;
    }
}
