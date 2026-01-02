<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Task extends Model
{
    protected $fillable = [
        'store_id',
        'assigned_to',
        'assigned_by',
        'title',
        'description',
        'priority',
        'status',
        'due_date',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function isOverdue(): bool
    {
        return $this->due_date && 
               $this->due_date->isPast() && 
               !in_array($this->status, ['completed', 'cancelled']);
    }
}
