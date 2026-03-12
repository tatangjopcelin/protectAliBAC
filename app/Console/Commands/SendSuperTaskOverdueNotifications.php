<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\SuperTask;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSuperTaskOverdueNotifications extends Command
{
    protected $signature = 'super-tasks:notify-overdue';

    protected $description = 'Envoie une notification aux employés et aux managers pour les super tâches en retard (semaine terminée, non complétées).';

    public function handle(NotificationService $notificationService): int
    {
        $today = Carbon::today();
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        $superTasks = SuperTask::with(['assignedTo', 'store'])
            ->whereDate('week_start_date', '<', $currentWeekStart->format('Y-m-d'))
            ->whereNotIn('status', ['completed', 'absent'])
            ->get();

        if ($superTasks->isEmpty()) {
            $this->info('Aucune super tâche en retard.');
            return Command::SUCCESS;
        }

        $sent = 0;

        foreach ($superTasks as $superTask) {
            $employee = $superTask->assignedTo;
            if (!$employee) {
                continue;
            }

            $weekStartLabel = Carbon::parse($superTask->week_start_date)->format('d/m/Y');

            // 1) Marquer la super tâche comme absence (non réalisée à temps)
            $superTask->status = 'absent';
            $superTask->save();

            // 2) Notification à l'employé
            if ($this->sendOverdueNotificationIfNotSent($notificationService, $employee, $superTask, $weekStartLabel, 'employee')) {
                $sent++;
            }

            // 2) Notification aux managers de l'établissement (admin, chef, directeur)
            $managers = User::where('store_id', $superTask->store_id)
                ->whereIn('role', ['admin', 'chef', 'director'])
                ->get();

            foreach ($managers as $manager) {
                if ($this->sendOverdueNotificationIfNotSent($notificationService, $manager, $superTask, $weekStartLabel, 'manager')) {
                    $sent++;
                }
            }
        }

        $this->info("Super tâches en retard : {$superTasks->count()} trouvée(s), {$sent} notification(s) envoyée(s).");
        return Command::SUCCESS;
    }

    /**
     * Envoie la notification \"super tâche en retard\" à un utilisateur si elle n'a pas déjà été envoyée aujourd'hui.
     */
    private function sendOverdueNotificationIfNotSent(NotificationService $notificationService, User $user, SuperTask $superTask, string $weekStartLabel, string $recipientType): bool
    {
        $today = Carbon::today()->format('Y-m-d');

        $alreadySent = Notification::where('user_id', $user->id)
            ->where('channel', 'super_task_overdue')
            ->where('data->super_task_id', (string) $superTask->id)
            ->where('data->recipient_type', $recipientType)
            ->whereDate('created_at', $today)
            ->exists();

        if ($alreadySent) {
            return false;
        }

        $typeLabel = $superTask->type === 'friteuse' ? 'Friteuse' : 'Chambre froide';
        $title = 'Super tâche en retard';
        $message = "La super tâche {$typeLabel} de la semaine du {$weekStartLabel} est en retard. Consultez l'app Brole.";

        $data = [
            'super_task_id' => (string) $superTask->id,
            'type' => $superTask->type,
            'week_start_date' => $superTask->week_start_date?->format('Y-m-d'),
            'recipient_type' => $recipientType,
            'screen' => 'super-tasks',
            'route' => '/tabs/super-tasks',
        ];

        try {
            $notificationService->sendNotification(
                $user,
                'super_task_overdue',
                $title,
                $message,
                $data,
                'all'
            );
            Log::info('Notification super tâche en retard envoyée', [
                'super_task_id' => $superTask->id,
                'user_id' => $user->id,
                'recipient_type' => $recipientType,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Erreur notification super tâche en retard', [
                'super_task_id' => $superTask->id,
                'user_id' => $user->id,
                'recipient_type' => $recipientType,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

