<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PayrollReportToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'month',
        'status',
        'rejection_reason',
        'sent_at',
        'viewed_at',
        'responded_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'responded_at' => 'datetime'
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Génère un nouveau token
     */
    public static function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    /**
     * Vérifie si le token est valide (pas expiré, pas encore utilisé)
     */
    public function isValid(): bool
    {
        // Le token est valide s'il est en statut "pending"
        return $this->status === 'pending';
    }
}
