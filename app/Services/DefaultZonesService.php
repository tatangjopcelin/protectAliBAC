<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Zone;

/**
 * Crée les 3 zones par défaut (Chambre froide, Congélateur, Magasin) pour un établissement.
 * Idempotent : firstOrCreate sur store_id + name.
 */
class DefaultZonesService
{
    /**
     * @return list<array{name: string, type: string, description: string, temperature: float|null}>
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'Chambre froide',
                'type' => 'chambre_froide',
                'description' => 'Stockage réfrigéré positif (+2 °C à +8 °C).',
                'temperature' => 4.00,
            ],
            [
                'name' => 'Congélateur',
                'type' => 'congelateur',
                'description' => 'Stockage surgelé (environ -18 °C).',
                'temperature' => -18.00,
            ],
            [
                'name' => 'Magasin',
                'type' => 'magasin',
                'description' => 'Zone de vente / réserve sèche accessible en boutique.',
                'temperature' => null,
            ],
        ];
    }

    /**
     * Garantit la présence des zones par défaut pour l'établissement donné.
     *
     * @return int Nombre de zones réellement créées lors de cet appel
     */
    public function ensureForStore(Store $store): int
    {
        $created = 0;

        foreach ($this->definitions() as $zoneData) {
            $zone = Zone::firstOrCreate(
                [
                    'store_id' => $store->id,
                    'name' => $zoneData['name'],
                ],
                [
                    'type' => $zoneData['type'],
                    'description' => $zoneData['description'],
                    'shelf' => null,
                    'bin' => null,
                    'temperature' => $zoneData['temperature'],
                    'is_active' => true,
                ]
            );

            if ($zone->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
