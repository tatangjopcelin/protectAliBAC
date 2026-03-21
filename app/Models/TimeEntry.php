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
        'clock_in_verification_code',
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
     * Utilise les heures réelles (clock_in à clock_out) moins les pauses.
     * Règle : si l'employé a pointé au moins une pause (WorkBreak), on ne compte que ces pauses ;
     * sinon on utilise break_duration (pointage manuel) ou la pause planifiée du planning.
     */
    public function calculateHoursWorked(): float
    {
        if (!$this->clock_in || !$this->clock_out) {
            return 0;
        }

        $start = Carbon::parse($this->clock_in);
        $end = Carbon::parse($this->clock_out);
        $totalBreakMinutes = 0;

        // Pauses pointées (WorkBreak) : si au moins une existe, elles remplacent le reste
        $punchedBreaks = $this->breaks ? $this->breaks->filter(fn ($b) => $b->end_break || $b->duration_minutes) : collect();
        if ($punchedBreaks->isNotEmpty()) {
            foreach ($punchedBreaks as $break) {
                if ($break->end_break && $break->duration_minutes) {
                    $totalBreakMinutes += $break->duration_minutes;
                } elseif ($break->start_break && $break->end_break) {
                    $startBreak = Carbon::parse($break->start_break);
                    $endBreak = Carbon::parse($break->end_break);
                    $totalBreakMinutes += abs($endBreak->diffInMinutes($startBreak));
                }
            }
        } else {
            // Aucune pause pointée : break_duration (pointage manuel) ou pause planifiée du planning
            if ($this->break_duration && (float) $this->break_duration > 0) {
                $totalBreakMinutes += (float) $this->break_duration * 60;
            }
            if ($totalBreakMinutes === 0 && $this->schedule && $this->schedule->start_break && $this->schedule->end_break) {
                $startBreak = Carbon::parse($this->schedule->start_break);
                $endBreak = Carbon::parse($this->schedule->end_break);
                $totalBreakMinutes += abs($endBreak->diffInMinutes($startBreak));
            }
        }

        $totalMinutes = abs($start->diffInMinutes($end));
        $netMinutes = max(0, $totalMinutes - $totalBreakMinutes);

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
