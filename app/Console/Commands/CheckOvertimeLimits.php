<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CheckOvertimeLimits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'time-entries:check-overtime';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie les pointages en cours et pointe automatiquement la sortie si la limite d\'heures supplémentaires est atteinte';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Vérification des limites d\'heures supplémentaires...');
        
        // Trouver tous les pointages en cours (clock_in mais pas de clock_out)
        $activeEntries = TimeEntry::with(['user', 'schedule'])
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->whereDate('date', Carbon::today())
            ->get();
        
        $autoClockedCount = 0;
        
        foreach ($activeEntries as $entry) {
            $user = $entry->user;
            $schedule = $entry->schedule;
            
            // Si pas de planning ou pas de limite définie, passer au suivant
            if (!$schedule || !$user->max_overtime_hours) {
                continue;
            }
            
            // Calculer les heures planifiées
            $scheduledStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_time);
            $scheduledEnd = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->end_time);
            
            $scheduledHours = $scheduledEnd->diffInMinutes($scheduledStart) / 60;
            if ($schedule->start_break && $schedule->end_break) {
                $breakStart = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_break);
                $breakEnd = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->end_break);
                $breakHours = $breakEnd->diffInMinutes($breakStart) / 60;
                $scheduledHours -= $breakHours;
            }
            
            // Calculer les heures travaillées jusqu'à maintenant
            $clockIn = Carbon::parse($entry->clock_in);
            $now = Carbon::now();
            $actualHours = $now->diffInMinutes($clockIn) / 60;
            
            // Soustraire les pauses
            if ($entry->break_duration) {
                $actualHours -= $entry->break_duration;
            }
            
            // Calculer les heures supplémentaires
            $overtimeHours = max(0, $actualHours - $scheduledHours);
            
            // Si les heures supplémentaires dépassent la limite, pointer automatiquement
            if ($overtimeHours > $user->max_overtime_hours) {
                // Calculer l'heure de sortie maximale autorisée
                $maxAllowedHours = $scheduledHours + $user->max_overtime_hours;
                
                // Ajouter les pauses
                if ($entry->break_duration) {
                    $maxAllowedHours += $entry->break_duration;
                }
                
                // Calculer l'heure de sortie automatique
                $autoClockOutTime = $clockIn->copy()->addHours($maxAllowedHours);
                
                // Pointer automatiquement
                $entry->clock_out = $autoClockOutTime;
                $entry->notes = ($entry->notes ? $entry->notes . "\n" : '') . 
                    "Pointage automatique (" . Carbon::now()->format('Y-m-d H:i') . "): " .
                    "Limite d'heures supplémentaires atteinte (" . 
                    number_format($user->max_overtime_hours, 2) . "h max autorisées). " .
                    "Sortie automatique à " . $autoClockOutTime->format('H:i');
                $entry->hours_worked = $entry->calculateHoursWorked();
                $entry->save();
                
                $autoClockedCount++;
                
                Log::info("Pointage automatique pour {$user->name}: limite d'heures sup atteinte", [
                    'user_id' => $user->id,
                    'time_entry_id' => $entry->id,
                    'overtime_hours' => $overtimeHours,
                    'max_allowed' => $user->max_overtime_hours,
                    'auto_clock_out' => $autoClockOutTime->format('Y-m-d H:i:s')
                ]);
            }
        }
        
        $this->info("Vérification terminée. {$autoClockedCount} pointage(s) automatique(s).");
        
        return Command::SUCCESS;
    }
}

