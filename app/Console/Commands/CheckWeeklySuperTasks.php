<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SuperTask;
use App\Models\User;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckWeeklySuperTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'super-tasks:check-weekly';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie que les super tâches sont assignées pour chaque semaine et envoie des alertes si nécessaire';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Vérification des super tâches hebdomadaires...');

        // Calculer le début de la semaine en cours (lundi)
        $today = Carbon::now();
        $dayOfWeek = $today->dayOfWeek;
        $diff = $dayOfWeek === 0 ? -6 : 1 - $dayOfWeek; // Ajuster pour lundi
        $currentWeekStart = $today->copy()->addDays($diff)->startOfDay();

        // Récupérer tous les établissements
        $stores = Store::all();
        $missingTasks = [];

        foreach ($stores as $store) {
            // Vérifier les super tâches de la semaine en cours pour cet établissement
            $friteuseExists = SuperTask::where('store_id', $store->id)
                ->where('type', 'friteuse')
                ->where('week_start_date', $currentWeekStart->format('Y-m-d'))
                ->exists();

            $chambreFroideExists = SuperTask::where('store_id', $store->id)
                ->where('type', 'chambre_froide')
                ->where('week_start_date', $currentWeekStart->format('Y-m-d'))
                ->exists();

            if (!$friteuseExists || !chambreFroideExists) {
                $missing = [];
                if (!$friteuseExists) $missing[] = 'Friteuse';
                if (!$chambreFroideExists) $missing[] = 'Chambre froide';

                $missingTasks[] = [
                    'store' => $store,
                    'missing' => $missing,
                    'week_start' => $currentWeekStart->format('Y-m-d')
                ];
            }
        }

        if (count($missingTasks) > 0) {
            $this->warn('Super tâches manquantes détectées:');
            
            foreach ($missingTasks as $missing) {
                $this->line("  - Établissement: {$missing['store']->name}");
                $this->line("    Manquantes: " . implode(', ', $missing['missing']));
                $this->line("    Semaine: {$missing['week_start']}");
                
                // Envoyer une notification aux admins/chefs/directeurs de l'établissement
                $this->notifyStoreManagers($missing['store'], $missing['missing'], $missing['week_start']);
            }

            Log::warning('Super tâches hebdomadaires manquantes', [
                'count' => count($missingTasks),
                'week_start' => $currentWeekStart->format('Y-m-d')
            ]);
        } else {
            $this->info('Toutes les super tâches sont assignées pour cette semaine.');
        }

        return Command::SUCCESS;
    }

    /**
     * Notifie les gestionnaires de l'établissement des super tâches manquantes
     */
    private function notifyStoreManagers(Store $store, array $missing, string $weekStart)
    {
        // Récupérer les admins, chefs et directeurs de l'établissement
        $managers = User::where('store_id', $store->id)
            ->whereIn('role', ['admin', 'chef', 'director'])
            ->whereNotNull('email_verified_at')
            ->get();

        foreach ($managers as $manager) {
            try {
                $subject = 'Alerte: Super tâches manquantes - ' . $store->name;
                $message = "Bonjour {$manager->name},\n\n";
                $message .= "Les super tâches suivantes ne sont pas encore assignées pour la semaine du " . Carbon::parse($weekStart)->format('d/m/Y') . " :\n\n";
                foreach ($missing as $task) {
                    $message .= "  - {$task}\n";
                }
                $message .= "\nVeuillez assigner ces super tâches depuis la page de gestion des super tâches.\n\n";
                $message .= "Cordialement,\nSystème ProtectAli";

                Mail::raw($message, function ($mail) use ($manager, $subject) {
                    $mail->to($manager->email)->subject($subject);
                });

                $this->line("  Notification envoyée à: {$manager->email}");
            } catch (\Exception $e) {
                $this->error("  Erreur envoi notification à {$manager->email}: " . $e->getMessage());
                Log::error('Erreur envoi notification super tâches manquantes', [
                    'user_id' => $manager->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
