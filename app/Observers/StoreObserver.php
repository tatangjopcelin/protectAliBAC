<?php

namespace App\Observers;

use App\Models\Store;
use App\Services\DefaultZonesService;

class StoreObserver
{
    public function __construct(
        private DefaultZonesService $defaultZonesService
    ) {}

    /**
     * À chaque création d'établissement (inscription, seed, etc.), ajouter les 3 zones par défaut.
     */
    public function created(Store $store): void
    {
        $this->defaultZonesService->ensureForStore($store);
    }
}
