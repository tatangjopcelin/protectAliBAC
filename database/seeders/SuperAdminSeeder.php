<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Créer / mettre à jour le super admin demandé
        User::updateOrCreate(
            ['email' => 'relaxya12@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('Athenabb@97'),
                'role' => 'super_admin',
                'store_id' => null,
                // Marqué comme vérifié pour éviter la demande de vérification d'email
                'email_verified_at' => now(),
            ]
        );
    }
}

