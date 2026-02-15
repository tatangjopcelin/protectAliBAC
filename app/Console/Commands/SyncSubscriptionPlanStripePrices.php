<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use Illuminate\Console\Command;

class SyncSubscriptionPlanStripePrices extends Command
{
    protected $signature = 'subscription:sync-stripe-prices';

    protected $description = 'Met à jour les ID de prix Stripe des offres d\'abonnement depuis le .env';

    public function handle(): int
    {
        $map = [
            'essentiel' => env('STRIPE_PRICE_ESSENTIEL'),
            'pro' => env('STRIPE_PRICE_PRO'),
            'pro-annuel' => env('STRIPE_PRICE_PRO_ANNUEL'),
        ];

        $updated = 0;
        foreach ($map as $slug => $stripePriceId) {
            $plan = SubscriptionPlan::where('slug', $slug)->first();
            if (!$plan) {
                $this->warn("Plan « {$slug} » introuvable en base. Exécutez d'abord : php artisan db:seed --class=SubscriptionPlanSeeder");
                continue;
            }
            if (empty($stripePriceId)) {
                $this->line("STRIPE_PRICE_" . strtoupper(str_replace('-', '_', $slug)) . " non défini dans .env → plan « {$plan->name } » inchangé.");
                continue;
            }
            $plan->stripe_price_id = $stripePriceId;
            $plan->save();
            $this->info("Plan « {$plan->name} » mis à jour avec le prix Stripe : {$stripePriceId}");
            $updated++;
        }

        if ($updated === 0) {
            $this->warn('Aucun plan mis à jour. Vérifiez que les variables STRIPE_PRICE_ESSENTIEL, STRIPE_PRICE_PRO, STRIPE_PRICE_PRO_ANNUEL sont définies dans votre fichier .env.');
        }

        return 0;
    }
}
