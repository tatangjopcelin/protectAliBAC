<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\Category;
use App\Models\Zone;
use App\Models\Store;
use App\Services\AlertService;
use Carbon\Carbon;

class TestExpirationNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:test-expiration-notifications {--send-emails : Envoyer réellement les emails}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Teste les notifications de péremption pour différents scénarios (7 jours, 3 jours, 1 jour, périmé)';

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
        $this->info('🧪 Test des notifications de péremption');
        $this->newLine();

        $sendEmails = $this->option('send-emails');
        
        if (!$sendEmails) {
            $this->warn('⚠️  Mode test (pas d\'emails envoyés). Utilisez --send-emails pour envoyer réellement les emails.');
            $this->newLine();
        }

        // Récupérer ou créer un magasin et une zone de test
        $store = Store::first();
        if (!$store) {
            $this->error('❌ Aucun magasin trouvé. Exécutez d\'abord les seeders.');
            return Command::FAILURE;
        }

        $zone = Zone::where('store_id', $store->id)->first();
        if (!$zone) {
            $zone = Zone::create([
                'name' => 'Zone Test',
                'store_id' => $store->id,
                'temperature_min' => 2,
                'temperature_max' => 8,
            ]);
            $this->info('✅ Zone de test créée');
        }

        $category = Category::first();
        if (!$category) {
            $this->error('❌ Aucune catégorie trouvée. Exécutez d\'abord les seeders.');
            return Command::FAILURE;
        }

        $today = Carbon::today();
        
        // Scénarios de test
        $scenarios = [
            [
                'name' => 'Produit périmé depuis 2 jours',
                'expiration_date' => $today->copy()->subDays(2),
                'expected_alert' => 'expired',
                'expected_severity' => 'critical',
            ],
            [
                'name' => 'Produit expire aujourd\'hui',
                'expiration_date' => $today,
                'expected_alert' => 'expiration',
                'expected_severity' => 'critical',
            ],
            [
                'name' => 'Produit expire demain',
                'expiration_date' => $today->copy()->addDay(),
                'expected_alert' => 'expiration',
                'expected_severity' => 'critical',
            ],
            [
                'name' => 'Produit expire dans 3 jours',
                'expiration_date' => $today->copy()->addDays(3),
                'expected_alert' => 'expiration',
                'expected_severity' => 'warning',
            ],
            [
                'name' => 'Produit expire dans 7 jours',
                'expiration_date' => $today->copy()->addDays(7),
                'expected_alert' => 'expiration',
                'expected_severity' => 'info',
            ],
        ];

        $this->info('📦 Création des produits de test...');
        $this->newLine();

        $results = [];

        foreach ($scenarios as $index => $scenario) {
            $this->line("Test {$index + 1}/" . count($scenarios) . ": {$scenario['name']}");
            
            // Créer un produit de test
            $product = Product::create([
                'name' => "Produit Test - {$scenario['name']}",
                'category_id' => $category->id,
                'zone_id' => $zone->id,
                'quantity' => 10,
                'unit' => 'kg',
                'expiration_date' => $scenario['expiration_date'],
                'is_active' => true,
                'status' => 'available',
            ]);

            // Vérifier la péremption et créer les alertes
            $this->alertService->checkProduct($product);

            // Vérifier si l'alerte a été créée
            $alert = \App\Models\Alert::where('product_id', $product->id)
                ->where('type', $scenario['expected_alert'])
                ->where('severity', $scenario['expected_severity'])
                ->first();

            if ($alert) {
                $this->info("  ✅ Alerte créée : {$alert->type} ({$alert->severity})");
                $this->line("     Message : {$alert->message}");
                
                // Compter les utilisateurs qui recevront l'email
                $usersCount = \App\Models\User::where('store_id', $store->id)
                    ->whereNotNull('email_verified_at')
                    ->count();
                
                $this->line("     📧 {$usersCount} utilisateur(s) recevront un email");
                
                $results[] = [
                    'scenario' => $scenario['name'],
                    'status' => '✅ Succès',
                    'alert_created' => true,
                    'alert_type' => $alert->type,
                    'alert_severity' => $alert->severity,
                    'users_count' => $usersCount,
                ];
            } else {
                $this->error("  ❌ Aucune alerte créée (attendu: {$scenario['expected_alert']}, {$scenario['expected_severity']})");
                $results[] = [
                    'scenario' => $scenario['name'],
                    'status' => '❌ Échec',
                    'alert_created' => false,
                ];
            }

            $this->newLine();
        }

        // Résumé
        $this->info('📊 Résumé des tests :');
        $this->newLine();
        
        $table = [];
        foreach ($results as $result) {
            $table[] = [
                'Scénario' => $result['scenario'],
                'Statut' => $result['status'],
                'Alerte créée' => $result['alert_created'] ? 'Oui' : 'Non',
                'Type' => $result['alert_type'] ?? '-',
                'Sévérité' => $result['alert_severity'] ?? '-',
                'Emails' => isset($result['users_count']) ? $result['users_count'] : '-',
            ];
        }

        $this->table(
            ['Scénario', 'Statut', 'Alerte créée', 'Type', 'Sévérité', 'Emails'],
            $table
        );

        // Option pour nettoyer les produits de test
        if ($this->confirm('Voulez-vous supprimer les produits de test créés ?', true)) {
            foreach ($scenarios as $index => $scenario) {
                $product = Product::where('name', 'like', "Produit Test - {$scenario['name']}%")->first();
                if ($product) {
                    \App\Models\Alert::where('product_id', $product->id)->delete();
                    $product->delete();
                }
            }
            $this->info('✅ Produits de test supprimés');
        }

        return Command::SUCCESS;
    }
}

