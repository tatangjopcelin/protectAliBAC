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

    protected $description = 'Détecte les absences : créneaux planifiés sans arrivée pointée (clock_in) pour ce planning';

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
            // Présent dès l’arrivée sur ce créneau (lié au schedule_id), départ non requis
            $hasArrival = TimeEntry::where('schedule_id', $schedule->id)
                ->whereNotNull('clock_in')
                ->exists();

            if (! $hasArrival) {
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
