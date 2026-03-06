<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalFeedRead extends Model
{
    protected $table = 'internal_feed_reads';

    protected $fillable = [
        'user_id',
        'store_id',
        'last_read_at',
        'last_opened_at',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'last_opened_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
