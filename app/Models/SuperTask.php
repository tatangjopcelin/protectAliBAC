<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class SuperTask extends Model
{
    protected $fillable = [
        'store_id',
        'type',
        'assigned_to',
        'assigned_by',
        'week_start_date',
        'day_of_week', // 1=lundi … 7=dimanche, jour à exécuter (affiché à l'employé)
        'status',
        'started_at',
        'completed_at',
        'oil_changed',
        'cleaned',
        'friteuse_notes',
        'organized',
        'chambre_froide_notes',
        'general_notes',
    ];

    protected $casts = [
        'week_start_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'oil_changed' => 'boolean',
        'cleaned' => 'boolean',
        'organized' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Vérifie si la super tâche est pour la semaine en cours
     */
    public function isCurrentWeek(): bool
    {
        $now = Carbon::now();
        $weekStart = Carbon::parse($this->week_start_date)->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();
        
        return $now->between($weekStart, $weekEnd);
    }

    /**
     * Vérifie si la super tâche est en retard
     */
    public function isOverdue(): bool
    {
        if ($this->status === 'completed') {
            return false;
        }
        
        $weekEnd = Carbon::parse($this->week_start_date)->endOfWeek();
        return Carbon::now()->isAfter($weekEnd);
    }
}
