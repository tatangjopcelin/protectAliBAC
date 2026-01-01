<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class TimeEntryCorrection extends Model
{
    protected $fillable = [
        'time_entry_id',
        'user_id',
        'requested_clock_in',
        'requested_clock_out',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'requested_clock_in' => 'datetime',
        'requested_clock_out' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Forcer la sérialisation des dates en UTC pour éviter les problèmes de fuseau horaire
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return Carbon::instance($date)->utc()->format('Y-m-d\TH:i:s.000000\Z');
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
