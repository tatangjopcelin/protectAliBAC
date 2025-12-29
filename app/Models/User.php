<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'zone_id',
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
        ];
    }

    public function stockMovements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockMovement::class);
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
}
