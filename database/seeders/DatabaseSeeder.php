<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            InitialDataSeeder::class,
        ]);

        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@boucherie.com',
            'role' => 'admin',
        ]);

        User::factory()->create([
            'name' => 'Chef',
            'email' => 'chef@boucherie.com',
            'role' => 'chef',
        ]);
    }
}
