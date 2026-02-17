<?php

namespace App\Services;

use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie les pointages en cours et complète automatiquement le pointage
 * (clock_out + hours_worked) lorsque la limite d'heures supplémentaires est dépassée.
 * Le pointage est alors traité comme si l'employé avait pointé le départ lui-même.
 */
class OvertimeCheckService
{
    /**
     * break_duration est stocké en minutes dans time_entries (voir BreakController, TimeEntryController).
     */
    private static function breakDurationInHours(TimeEntry $entry): float
    {
        if (!$entry->break_duration) {
            return 0;
        }
        return (float) $entry->break_duration / 60;
    }

    /**
     * Exécute la vérification pour tous les pointages en cours (aujourd'hui).
     * Retourne le nombre de pointages complétés automatiquement.
     */
    public function runCheck(): int
    {
        $today = Carbon::today();
        $activeEntries = TimeEntry::with(['user', 'schedule'])
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->whereDate('date', $today)
            ->get();

        $autoClockedCount = 0;

        foreach ($activeEntries as $entry) {
            $user = $entry->user;
            $schedule = $entry->schedule;

            Log::info("Vérification pointage", [
                'entry_id' => $entry->id,
                'user_id' => $user->id,
                'user_name' => $user->name ?? 'N/A',
                'has_schedule' => $schedule ? 'yes' : 'no',
                'schedule_id' => $schedule->id ?? null,
                'max_overtime_hours' => $user->max_overtime_hours ?? 'not set',
            ]);

            if (!$schedule || !$user->max_overtime_hours) {
                Log::info("Pointage ignoré: pas de planning ou max_overtime_hours non défini", [
                    'entry_id' => $entry->id,
                    'user_id' => $user->id,
                ]);
                continue;
            }

            $scheduledStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_time);
            $scheduledEnd = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->end_time);
            // Utiliser abs() pour garantir une valeur positive même si l'ordre est inversé
            $scheduledHours = abs($scheduledEnd->diffInMinutes($scheduledStart)) / 60;
            
            Log::info("Calcul durée programmée", [
                'entry_id' => $entry->id,
                'schedule_date' => $schedule->date->format('Y-m-d'),
                'schedule_start_time' => $schedule->start_time,
                'schedule_end_time' => $schedule->end_time,
                'scheduled_start_parsed' => $scheduledStart->format('Y-m-d H:i:s'),
                'scheduled_end_parsed' => $scheduledEnd->format('Y-m-d H:i:s'),
                'scheduled_hours' => $scheduledHours,
            ]);
            if ($schedule->start_break && $schedule->end_break) {
                $breakStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_break);
                $breakEnd = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->end_break);
                $breakHours = abs($breakEnd->diffInMinutes($breakStart)) / 60;
                $scheduledHours -= $breakHours;
            }

            // Ne jamais clôturer si la durée programmée est nulle ou négative (planning invalide)
            if ($scheduledHours <= 0) {
                Log::info("Pointage ignoré: durée programmée invalide", [
                    'entry_id' => $entry->id,
                    'user_id' => $user->id,
                    'scheduled_hours' => $scheduledHours,
                    'schedule_start' => $schedule->start_time,
                    'schedule_end' => $schedule->end_time,
                ]);
                continue;
            }

            $clockIn = Carbon::parse($entry->clock_in);
            $now = Carbon::now();

            // Ne jamais clôturer avant l'heure de fin programmée (ex: programmé 02:50-03:50 => pas de sortie auto avant 03:50)
            if ($now->lt($scheduledEnd)) {
                Log::info("Pointage ignoré: heure actuelle avant fin programmée", [
                    'entry_id' => $entry->id,
                    'user_id' => $user->id,
                    'now' => $now->format('Y-m-d H:i:s'),
                    'scheduled_end' => $scheduledEnd->format('Y-m-d H:i:s'),
                ]);
                continue;
            }

            // Utiliser abs() pour garantir une valeur positive (problème de fuseau horaire possible)
            $actualHours = abs($now->diffInMinutes($clockIn)) / 60;
            $actualHours -= self::breakDurationInHours($entry);
            // S'assurer que actualHours reste positif (les pauses ne peuvent pas dépasser le temps travaillé)
            $actualHours = max(0, $actualHours);

            // Heures sup = temps travaillé au-delà de la durée programmée
            $overtimeHours = max(0, $actualHours - $scheduledHours);

            Log::info("Calcul heures sup", [
                'entry_id' => $entry->id,
                'user_id' => $user->id,
                'clock_in' => $clockIn->format('Y-m-d H:i:s'),
                'now' => $now->format('Y-m-d H:i:s'),
                'scheduled_hours' => $scheduledHours,
                'actual_hours' => $actualHours,
                'break_hours' => self::breakDurationInHours($entry),
                'overtime_hours' => $overtimeHours,
                'max_overtime_hours' => $user->max_overtime_hours,
                'should_close' => $overtimeHours > $user->max_overtime_hours ? 'yes' : 'no',
            ]);

            // Clôturer seulement si les heures sup dépassent la limite autorisée
            if ($overtimeHours <= $user->max_overtime_hours) {
                Log::info("Pointage ignoré: heures sup dans la limite", [
                    'entry_id' => $entry->id,
                    'user_id' => $user->id,
                    'overtime_hours' => $overtimeHours,
                    'max_allowed' => $user->max_overtime_hours,
                ]);
                continue;
            }

            $maxAllowedHours = $scheduledHours + $user->max_overtime_hours;
            $maxAllowedHours += self::breakDurationInHours($entry);

            $autoClockOutTime = $clockIn->copy()->addHours($maxAllowedHours);

            $entry->clock_out = $autoClockOutTime;
            $entry->notes = ($entry->notes ? $entry->notes . "\n" : '') .
                "Pointage automatique (" . Carbon::now()->format('Y-m-d H:i') . "): " .
                "Limite d'heures supplémentaires atteinte (" .
                number_format($user->max_overtime_hours, 2) . "h max autorisées). " .
                "Sortie enregistrée à " . $autoClockOutTime->format('H:i') . " (comme si l'employé avait pointé le départ).";
            $entry->hours_worked = $entry->calculateHoursWorked();
            $entry->save();

            if ($schedule && $schedule->status === 'request') {
                $schedule->end_time = $autoClockOutTime->format('H:i:s');
                $schedule->notes = ($schedule->notes ? $schedule->notes . "\n" : '') .
                    'Heures réelles (dont sortie automatique): ' . Carbon::parse($entry->clock_in)->format('H:i') . ' - ' . $autoClockOutTime->format('H:i');
                $schedule->save();
            }

            $autoClockedCount++;
            Log::info("Pointage automatique pour {$user->name}: limite d'heures sup atteinte", [
                'user_id' => $user->id,
                'time_entry_id' => $entry->id,
                'overtime_hours' => $overtimeHours,
                'max_allowed' => $user->max_overtime_hours,
                'auto_clock_out' => $autoClockOutTime->format('Y-m-d H:i:s'),
            ]);
        }

        return $autoClockedCount;
    }
}
