<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Cashier\Billable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, Billable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar',
        'password',
        'role',
        'shared_permission_overrides',
        'store_id',
        'zone_id',
        'max_overtime_hours',
        'clock_in_code',
        'clock_in_code_expires_at',
        'email_verified_at',
        'email_verification_code',
        'email_verification_code_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'max_overtime_hours' => 'decimal:2',
            'clock_in_code_expires_at' => 'datetime',
            'email_verification_code_expires_at' => 'datetime',
            'shared_permission_overrides' => 'array',
        ];
    }

    /** Clés des permissions partagées (admin peut les activer/désactiver pour director/chef) */
    public const SHARED_PERMISSIONS = ['planning', 'leaves', 'time_entry', 'tasks'];

    /**
     * Vérifie si l'utilisateur a la permission partagée (pour director/chef, selon les overrides admin).
     */
    public function hasSharedPermission(string $key): bool
    {
        if ($this->role === 'admin') {
            return true;
        }
        if ($this->role === 'machine') {
            return in_array($key, ['planning', 'time_entry'], true);
        }
        if (!in_array($this->role, ['director', 'chef'], true)) {
            return false;
        }
        $overrides = $this->shared_permission_overrides ?? [];
        return array_key_exists($key, $overrides) ? (bool) $overrides[$key] : true;
    }

    /** Retourne les overrides actuels pour l’affichage (admin). */
    public function getSharedPermissionOverrides(): array
    {
        if ($this->role === 'machine') {
            return array_combine(
                self::SHARED_PERMISSIONS,
                array_map(fn ($k) => in_array($k, ['planning', 'time_entry'], true), self::SHARED_PERMISSIONS)
            );
        }
        $overrides = $this->shared_permission_overrides ?? [];
        $result = [];
        foreach (self::SHARED_PERMISSIONS as $key) {
            $result[$key] = array_key_exists($key, $overrides) ? (bool) $overrides[$key] : true;
        }
        return $result;
    }

    public function stockMovements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function schedules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Schedule::class);
    }

    public function timeEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\TimeEntry::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isChef(): bool
    {
        return $this->role === 'chef';
    }

    public function isCook(): bool
    {
        return $this->role === 'cook';
    }

    public function isStorekeeper(): bool
    {
        return $this->role === 'storekeeper';
    }

    public function isAccountant(): bool
    {
        return $this->role === 'accountant';
    }

    public function isButcher(): bool
    {
        return $this->role === 'butcher';
    }

    public function isServer(): bool
    {
        return $this->role === 'server';
    }

    public function isDirector(): bool
    {
        return $this->role === 'director';
    }

    public function isMachine(): bool
    {
        return $this->role === 'machine';
    }

    public function notificationPreferences(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function notifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function zone(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function ownedStore(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Store::class, 'created_by');
    }

    public function leaves(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Leave::class);
    }

    /**
     * Vérifie si l'utilisateur a une permission spécifique
     */
    public function hasPermission(string $permissionName): bool
    {
        // Admin a tous les droits
        if ($this->role === 'admin') {
            return true;
        }

        // Vérifier si le rôle de l'utilisateur a la permission
        $permission = \App\Models\Permission::where('name', $permissionName)->first();
        if (!$permission) {
            return false;
        }

        return \App\Models\RolePermission::where('role', $this->role)
            ->where('permission_id', $permission->id)
            ->exists();
    }

    /**
     * Vérifie si l'utilisateur peut effectuer une action sur une ressource
     * Note: Renommée en canPerform pour éviter le conflit avec la méthode can() de Laravel
     */
    public function canPerform(string $action, string $resource): bool
    {
        $permissionName = "{$resource}.{$action}";
        return $this->hasPermission($permissionName);
    }

    /**
     * Envoyer la notification de vérification d'email personnalisée
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new \App\Notifications\VerifyEmailNotification());
    }

    /**
     * Envoyer la notification de réinitialisation de mot de passe personnalisée
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }
}
