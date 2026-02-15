<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'dates',
        'start_date',
        'end_date',
        'number_of_days',
        'status',
        'type',
        'created_by',
        'approved_by',
        'approved_at',
        'is_paid',
        'notes',
        'rejection_reason',
        'seen_by_user_at',
    ];

    protected $casts = [
        'dates' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
        'is_paid' => 'boolean',
        'seen_by_user_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Calculer le nombre de jours à partir d'un tableau de dates
     */
    public static function calculateNumberOfDays(array $dates): int
    {
        return count($dates);
    }
}
