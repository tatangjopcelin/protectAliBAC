<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Services\DefaultZonesService;
use Illuminate\Database\Seeder;

/**
 * Crée 3 zones par défaut pour chaque établissement :
 * Chambre froide, Congélateur, Magasin.
 * Idempotent : même logique que StoreObserver / DefaultZonesService.
 */
class DefaultZonesSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(DefaultZonesService::class);
        $stores = Store::query()->get();
        $created = 0;

        foreach ($stores as $store) {
            $created += $service->ensureForStore($store);
        }

        $this->command?->info("✅ Zones par défaut : {$created} zone(s) créée(s) pour {$stores->count()} établissement(s).");
    }
}
