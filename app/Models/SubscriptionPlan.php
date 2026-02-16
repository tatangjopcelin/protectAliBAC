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

    /**
     * Limites par plan (max_users = null signifie illimité).
     * @return array{max_users: int|null}
     */
    public static function getLimitsBySlug(string $slug): array
    {
        $limits = [
            'gratuit' => ['max_users' => 3],
            'essentiel' => ['max_users' => 5],
            'pro' => ['max_users' => null],
            'pro-annuel' => ['max_users' => null],
        ];
        return $limits[$slug] ?? ['max_users' => null];
    }

    /**
     * Les plans Pro et Pro Annuel ont accès à Congés, Tâches, Traçabilité, IA.
     * Gratuit et Essentiel n'ont pas accès à ces modules.
     */
    public static function hasProFeatures(string $slug): bool
    {
        return in_array($slug, ['pro', 'pro-annuel'], true);
    }
}
