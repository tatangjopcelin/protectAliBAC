<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Gratuit',
                'slug' => 'gratuit',
                'stripe_price_id' => null,
                'amount_cents' => 0,
                'interval' => 'month',
                'features' => ['15 jours d\'essai', 'Jusqu\'à 3 utilisateurs', 'Produits & stock', 'Alertes', 'Liste de courses', 'Planning', 'Pointage', 'Contrôle des produits'],
                'sort_order' => 0,
                'is_active' => true,
            ],
            [
                'name' => 'Essentiel',
                'slug' => 'essentiel',
                'stripe_price_id' => env('STRIPE_PRICE_ESSENTIEL', null),
                'amount_cents' => 1990,
                'interval' => 'month',
                'features' => ['Jusqu\'à 5 utilisateurs', 'Produits & stock', 'Alertes', 'Liste de courses', 'Planning', 'Pointage', 'Contrôle des produits'],
                'sort_order' => 1,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'stripe_price_id' => env('STRIPE_PRICE_PRO', null),
                'amount_cents' => 4990,
                'interval' => 'month',
                'features' => ['Utilisateurs illimités', 'Tout Essentiel', 'Planning & pointage', 'Congés', 'Tâches', 'Traçabilité', 'IA'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Pro Annuel',
                'slug' => 'pro-annuel',
                'stripe_price_id' => env('STRIPE_PRICE_PRO_ANNUEL', null),
                'amount_cents' => 49900,
                'interval' => 'year',
                'features' => ['Tout Pro', '2 mois offerts', 'Facturation annuelle', 'Économiser jusqu\'à 100 €'],
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
