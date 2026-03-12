<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\SuperTask;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSuperTaskDueTodayNotifications extends Command
{
    protected $signature = 'super-tasks:notify-due-today';

    protected $description = 'Envoie un rappel (email, push, SMS, in-app) aux employés qui ont une super tâche à effectuer aujourd\'hui (selon day_of_week).';

    public function handle(NotificationService $notificationService): int
    {
        $today = Carbon::today();
        $todayStr = $today->format('Y-m-d');
        $dayOfWeek = (int) $today->format('N'); // 1 = lundi … 7 = dimanche (ISO-8601)
        $currentWeekMonday = $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');

        $superTasks = SuperTask::with(['assignedTo'])
            ->whereNotNull('day_of_week')
            ->where('day_of_week', $dayOfWeek)
            ->whereDate('week_start_date', $currentWeekMonday)
            ->whereNotIn('status', ['completed', 'absent'])
            ->get();

        if ($superTasks->isEmpty()) {
            $this->info('Aucune super tâche prévue pour aujourd\'hui (jour ' . $dayOfWeek . ').');
            return Command::SUCCESS;
        }

        $sent = 0;
        $typeLabels = ['friteuse' => 'Friteuse', 'chambre_froide' => 'Chambre froide'];

        foreach ($superTasks as $superTask) {
            $user = $superTask->assignedTo;
            if (!$user) {
                continue;
            }

            $alreadySent = Notification::where('user_id', $user->id)
                ->where('channel', 'super_task_due_today')
                ->where('data->super_task_id', (string) $superTask->id)
                ->whereDate('created_at', $todayStr)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $typeLabel = $typeLabels[$superTask->type] ?? $superTask->type;
            $weekLabel = Carbon::parse($superTask->week_start_date)->format('d/m/Y');
            $title = 'Super tâche à effectuer aujourd\'hui';
            $message = "Vous avez une super tâche à effectuer aujourd'hui : {$typeLabel} (semaine du {$weekLabel}). Consultez l'app Brole.";

            $data = [
                'super_task_id' => (string) $superTask->id,
                'type' => $superTask->type,
                'week_start_date' => $superTask->week_start_date?->format('Y-m-d'),
                'screen' => 'tasks',
                'route' => '/tabs/tasks',
            ];

            try {
                $notificationService->sendNotification(
                    $user,
                    'super_task_due_today',
                    $title,
                    $message,
                    $data,
                    'all'
                );
                $sent++;
                $this->line("  Notifié : {$user->name} – {$typeLabel} (semaine du {$weekLabel})");
            } catch (\Throwable $e) {
                Log::error('Erreur notification super tâche due aujourd\'hui', [
                    'super_task_id' => $superTask->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  Échec super tâche #{$superTask->id} : " . $e->getMessage());
            }
        }

        $this->info("Super tâches dues aujourd'hui : {$superTasks->count()} trouvée(s), {$sent} notification(s) envoyée(s).");
        return Command::SUCCESS;
    }
}
