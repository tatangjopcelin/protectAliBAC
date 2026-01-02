<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Store;
use App\Models\Category;

class InitialDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Créer un magasin par défaut
        $store = Store::firstOrCreate(
            ['name' => 'Restaurant Principal'],
            [
                'description' => 'Magasin principal du restaurant',
                'is_active' => true,
            ]
        );

        // Créer les catégories de produits
        $categories = [
            ['name' => 'Viande', 'color' => '#dc3545', 'description' => 'Viandes et charcuteries'],
            ['name' => 'Légumes', 'color' => '#28a745', 'description' => 'Légumes frais'],
            ['name' => 'Épices', 'color' => '#ffc107', 'description' => 'Épices et condiments'],
            ['name' => 'Boissons', 'color' => '#17a2b8', 'description' => 'Boissons'],
            ['name' => 'Produits laitiers', 'color' => '#6c757d', 'description' => 'Lait, fromage, yaourt'],
            ['name' => 'Fruits', 'color' => '#fd7e14', 'description' => 'Fruits frais'],
            ['name' => 'Pâtes & Céréales', 'color' => '#e83e8c', 'description' => 'Pâtes, riz, céréales'],
        ];

        foreach ($categories as $categoryData) {
            Category::firstOrCreate(
                ['name' => $categoryData['name']],
                array_merge($categoryData, ['is_active' => true])
            );
        }

        $this->command->info('✅ Données initiales créées avec succès !');
        $this->command->info("   - 1 magasin créé");
        $this->command->info("   - " . count($categories) . " catégories créées");
    }
}
