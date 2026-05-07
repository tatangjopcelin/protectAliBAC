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

    /**
     * Coordonnées magasin + contacts clés (admin, directeur, chef) pour emails et commandes fournisseurs.
     *
     * @return array{store: array{name: string, address: ?string, phone: ?string}, staff: list<array{role: string, role_label: string, name: string, email: string, phone: ?string}>}
     */
    public function supplierOrderContactsPayload(): array
    {
        $roleOrder = ['admin' => 0, 'director' => 1, 'chef' => 2];
        $roleLabels = [
            'admin' => 'Administrateur',
            'director' => 'Directeur',
            'chef' => 'Chef',
        ];

        $staff = $this->users()
            ->whereIn('role', ['admin', 'director', 'chef'])
            ->orderBy('name')
            ->get(['name', 'email', 'phone', 'role'])
            ->sortBy(fn (User $u) => $roleOrder[$u->role] ?? 99)
            ->values()
            ->map(function (User $u) use ($roleLabels) {
                return [
                    'role' => $u->role,
                    'role_label' => $roleLabels[$u->role] ?? $u->role,
                    'name' => $u->name,
                    'email' => $u->email,
                    'phone' => $u->phone,
                ];
            })
            ->all();

        return [
            'store' => [
                'name' => $this->name,
                'address' => $this->address,
                'phone' => $this->phone,
            ],
            'staff' => $staff,
        ];
    }

    /**
     * Numéros de téléphone des utilisateurs admin, directeur et chef (sans noms ni rôles),
     * ordre admin → directeur → chef, dédupliqués, séparés par « / ».
     */
    public function keyStaffPhonesSlashSeparated(): string
    {
        $roleOrder = ['admin' => 0, 'director' => 1, 'chef' => 2];
        $users = $this->users()
            ->whereIn('role', ['admin', 'director', 'chef'])
            ->get(['phone', 'role'])
            ->sortBy(fn (User $u) => $roleOrder[$u->role] ?? 99)
            ->values();

        $seen = [];
        $phones = [];
        foreach ($users as $user) {
            $p = trim((string) ($user->phone ?? ''));
            if ($p === '' || isset($seen[$p])) {
                continue;
            }
            $seen[$p] = true;
            $phones[] = $p;
        }

        return implode(' / ', $phones);
    }
}
