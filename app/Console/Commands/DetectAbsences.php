<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Schedule;
use App\Models\TimeEntry;
use App\Models\Absence;
use Carbon\Carbon;

class DetectAbsences extends Command
{
    protected $signature = 'absences:detect {--date= : Date à traiter (Y-m-d), défaut = hier}';

    protected $description = 'Détecte les absences : employés programmés qui n\'ont pas pointé le jour donné (à lancer le lendemain)';

    public function handle(): int
    {
        $dateInput = $this->option('date');
        $targetDate = $dateInput
            ? Carbon::parse($dateInput)->startOfDay()
            : Carbon::yesterday()->startOfDay();

        $dateStr = $targetDate->format('Y-m-d');
        $this->info("Détection des absences pour le {$dateStr}...");

        $schedules = Schedule::whereDate('date', $dateStr)
            ->where('status', '!=', 'cancelled')
            ->get();

        $created = 0;
        foreach ($schedules as $schedule) {
            $hasEntry = TimeEntry::where('user_id', $schedule->user_id)
                ->whereDate('date', $dateStr)
                ->exists();

            if (!$hasEntry) {
                Absence::firstOrCreate(
                    [
                        'user_id' => $schedule->user_id,
                        'date' => $dateStr,
                    ],
                    [
                        'schedule_id' => $schedule->id,
                        'store_id' => $schedule->store_id,
                    ]
                );
                $created++;
            }
        }

        $this->info("Terminé. {$created} absence(s) enregistrée(s) pour le {$dateStr}.");
        return Command::SUCCESS;
    }
}
