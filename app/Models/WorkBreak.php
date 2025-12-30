<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class WorkBreak extends Model
{
    protected $table = 'breaks'; // Spécifier explicitement le nom de la table
    
    protected $fillable = [
        'time_entry_id',
        'user_id',
        'start_break',
        'end_break',
        'duration_minutes',
        'notes',
    ];

    protected $casts = [
        'start_break' => 'datetime',
        'end_break' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calcule la durée de la pause en minutes
     */
    public function calculateDuration(): int
    {
        if (!$this->start_break || !$this->end_break) {
            return 0;
        }

        $start = Carbon::parse($this->start_break);
        $end = Carbon::parse($this->end_break);

        return max(0, $end->diffInMinutes($start));
    }
}
