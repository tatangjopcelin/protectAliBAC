<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = [
        'name',
        'description',
        'address',
        'phone',
        'is_active',
        'establishment_code',
        'created_by',
        'trial_ends_at',
        'free_access_granted_at',
        'clock_in_verification_method',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trial_ends_at' => 'datetime',
        'free_access_granted_at' => 'datetime',
    ];

    /**
     * Générer un code d'établissement unique à 4 chiffres
     */
    public static function generateEstablishmentCode(): string
    {
        do {
            $code = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::where('establishment_code', $code)->exists());
        
        return $code;
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
