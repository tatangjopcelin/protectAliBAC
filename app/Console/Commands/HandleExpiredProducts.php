<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HandleExpiredProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:handle-expired {--dry-run : Afficher sans modifier}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gère automatiquement les produits périmés en réduisant leur stock à 0';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Recherche des produits périmés avec stock...');
        
        $expiredProducts = Product::where('is_active', true)
            ->where('expiration_date', '<', Carbon::today())
            ->where('quantity', '>', 0)
            ->with('zone.store')
            ->get();

        if ($expiredProducts->isEmpty()) {
            $this->info('✅ Aucun produit périmé avec stock trouvé.');
            return Command::SUCCESS;
        }

        $this->info("📦 Trouvé {$expiredProducts->count()} produit(s) périmé(s) avec stock.");

        if ($this->option('dry-run')) {
            $this->warn('🔍 Mode dry-run - Aucune modification ne sera effectuée');
            foreach ($expiredProducts as $product) {
                $location = $product->zone ? "Zone: {$product->zone->name}" : "Localisation inconnue";
                $this->line("  - {$product->name}: {$product->quantity} {$product->unit} - {$location}");
            }
            return Command::SUCCESS;
        }

        $processed = 0;
        $errors = [];

        foreach ($expiredProducts as $product) {
            try {
                DB::transaction(function () use ($product, &$processed) {
                    $quantityToRemove = $product->quantity;

                    // Créer un mouvement de stock
                    StockMovement::create([
                        'product_id' => $product->id,
                        'user_id' => null, // Système automatique
                        'type' => 'wasted',
                        'quantity' => $quantityToRemove,
                        'notes' => "Produit périmé - Retiré automatiquement du stock",
                    ]);

                    // Réduire le stock à 0
                    $product->quantity = 0;
                    $product->status = 'expired';
                    $product->save();

                    $processed++;
                    
                    $location = $product->zone ? "Zone: {$product->zone->name}" : "Localisation inconnue";
                    $this->line("  ✅ {$product->name}: {$quantityToRemove} {$product->unit} retiré(s) - {$location}");
                });
            } catch (\Exception $e) {
                $errors[] = [
                    'product' => $product->name,
                    'error' => $e->getMessage()
                ];
                $this->error("  ❌ Erreur pour {$product->name}: {$e->getMessage()}");
            }
        }

        $this->info("✅ Traitement terminé: {$processed} produit(s) traité(s)");
        
        if (!empty($errors)) {
            $this->warn("⚠️  " . count($errors) . " erreur(s) rencontrée(s)");
        }

        return Command::SUCCESS;
    }
}
