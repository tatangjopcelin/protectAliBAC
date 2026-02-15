<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'stripe_price_id',
        'amount_cents',
        'interval',
        'features',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function getPriceFormattedAttribute(): string
    {
        $euros = $this->amount_cents / 100;
        return number_format($euros, 2, ',', ' ') . ' €';
    }

    public function getIntervalLabelAttribute(): string
    {
        return $this->interval === 'year' ? '/ an' : '/ mois';
    }
}
