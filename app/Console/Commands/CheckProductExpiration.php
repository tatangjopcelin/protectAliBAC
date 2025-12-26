<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AlertService;

class CheckProductExpiration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:check-expiration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie les dates de péremption des produits et crée des alertes (7 jours, 3 jours, 1 jour, périmés)';

    protected $alertService;

    public function __construct(AlertService $alertService)
    {
        parent::__construct();
        $this->alertService = $alertService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Vérification des dates de péremption des produits...');
        
        $this->alertService->checkAllProducts();
        
        $this->info('✅ Vérification terminée. Les alertes ont été créées/mises à jour.');
        
        // Afficher un résumé
        $alertsCount = \App\Models\Alert::where('is_read', false)->count();
        $this->info("📊 Nombre d'alertes non lues : {$alertsCount}");
        
        return Command::SUCCESS;
    }
}
