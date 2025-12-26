<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AISuggestion extends Model
{
    protected $fillable = [
        'type',
        'title',
        'description',
        'data',
        'product_id',
        'recipe_id',
        'user_id',
        'status',
        'confidence_score',
        'viewed_at',
    ];

    protected $casts = [
        'data' => 'array',
        'confidence_score' => 'decimal:2',
        'viewed_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
