<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class PendingRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'zone_id',
        'email_verification_code',
        'email_verification_code_expires_at',
        'registration_type', // 'create_store' ou 'join_store'
        'store_name',
        'store_address',
        'store_phone',
        'establishment_code',
    ];

    protected $casts = [
        'email_verification_code_expires_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Vérifie si le code a expiré
     */
    public function isCodeExpired(): bool
    {
        return $this->email_verification_code_expires_at && now()->gt($this->email_verification_code_expires_at);
    }
}
