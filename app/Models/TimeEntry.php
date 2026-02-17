<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class TimeEntry extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'schedule_id',
        'date',
        'clock_in',
        'clock_out',
        'hours_worked',
        'break_duration',
        'status',
        'location',
        'notes',
        'clock_in_photo',
        'clock_out_signature',
    ];

    protected $casts = [
        'date' => 'date',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'hours_worked' => 'decimal:2',
        'break_duration' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function breaks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkBreak::class);
    }

    public function corrections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TimeEntryCorrection::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Calcule les heures travaillées automatiquement
     * Utilise les heures réelles travaillées (clock_in à clock_out) moins les pauses
     */
    public function calculateHoursWorked(): float
    {
        if (!$this->clock_in || !$this->clock_out) {
            return 0;
        }

        $start = Carbon::parse($this->clock_in);
        $end = Carbon::parse($this->clock_out);
        
        // Calculer le total des pauses (break_duration + pauses réelles)
        $totalBreakMinutes = 0;
        
        // Ajouter break_duration si disponible (stocké en heures, convertir en minutes)
        if ($this->break_duration) {
            $totalBreakMinutes += (float)$this->break_duration * 60; // Convertir les heures en minutes
        }
        
        // Ajouter toutes les pauses réelles
        if ($this->breaks) {
            foreach ($this->breaks as $break) {
                if ($break->end_break && $break->duration_minutes) {
                    $totalBreakMinutes += $break->duration_minutes;
                } elseif ($break->start_break && $break->end_break) {
                    $startBreak = Carbon::parse($break->start_break);
                    $endBreak = Carbon::parse($break->end_break);
                    $totalBreakMinutes += abs($endBreak->diffInMinutes($startBreak));
                }
            }
        }
        
        // Calculer la différence en minutes pour avoir une précision exacte
        // Utiliser abs() pour s'assurer d'avoir une valeur positive
        $totalMinutes = abs($start->diffInMinutes($end));
        $netMinutes = max(0, $totalMinutes - $totalBreakMinutes);
        
        // Convertir en heures avec 2 décimales
        return round($netMinutes / 60, 2);
    }

    /**
     * Vérifie si l'utilisateur est en retard
     */
    public function isLate(): bool
    {
        if (!$this->schedule || !$this->clock_in) {
            return false;
        }

        $scheduledStart = Carbon::parse($this->schedule->date->format('Y-m-d') . ' ' . $this->schedule->start_time);
        $actualStart = Carbon::parse($this->clock_in);

        return $actualStart->gt($scheduledStart->addMinutes(15)); // 15 minutes de tolérance
    }
}
