<?php

/**
 * Script de test pour les notifications de péremption
 * Usage: php test-expiration.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Product;
use App\Models\Category;
use App\Models\Zone;
use App\Models\Store;
use App\Models\Alert;
use App\Models\User;
use App\Services\AlertService;
use Carbon\Carbon;

echo "🧪 Test des notifications de péremption\n\n";

$alertService = app(AlertService::class);

// Récupérer ou créer un magasin et une zone de test
$store = Store::first();
if (!$store) {
    echo "❌ Aucun magasin trouvé. Exécutez d'abord les seeders.\n";
    exit(1);
}

$zone = Zone::where('store_id', $store->id)->first();
if (!$zone) {
    $zone = Zone::create([
        'name' => 'Zone Test',
        'store_id' => $store->id,
        'temperature_min' => 2,
        'temperature_max' => 8,
    ]);
    echo "✅ Zone de test créée\n";
}

$category = Category::first();
if (!$category) {
    echo "❌ Aucune catégorie trouvée. Exécutez d'abord les seeders.\n";
    exit(1);
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

echo "📦 Création des produits de test...\n\n";

$results = [];

foreach ($scenarios as $index => $scenario) {
    echo "Test " . ($index + 1) . "/" . count($scenarios) . ": {$scenario['name']}\n";
    
    // Créer un produit de test
    $product = Product::create([
        'name' => "Produit Test - {$scenario['name']}",
        'category_id' => $category->id,
        'zone_id' => $zone->id,
        'quantity' => 10,
        'unit' => 'kg',
        'reception_date' => $today->copy()->subDays(10), // Date de réception il y a 10 jours
        'expiration_date' => $scenario['expiration_date'],
        'is_active' => true,
        'status' => 'ok', // Le statut sera mis à jour automatiquement par checkProduct
    ]);

    // Vérifier la péremption et créer les alertes
    $alertService->checkProduct($product);

    // Vérifier si l'alerte a été créée
    // Pour les produits qui expirent aujourd'hui, vérifier aussi si une alerte 'expired' existe (car isPast peut être true)
    $alert = null;
    if ($scenario['expected_alert'] === 'expiration' && $scenario['expiration_date']->isToday()) {
        // Si le produit expire aujourd'hui, vérifier d'abord si une alerte 'expired' existe
        $alert = Alert::where('product_id', $product->id)
            ->whereIn('type', ['expired', 'expiration'])
            ->where('severity', 'critical')
            ->first();
    } else {
        $alert = Alert::where('product_id', $product->id)
            ->where('type', $scenario['expected_alert'])
            ->where('severity', $scenario['expected_alert'] === 'expired' ? 'critical' : $scenario['expected_severity'])
            ->first();
    }
    
    // Si pas d'alerte trouvée, afficher toutes les alertes pour ce produit pour debug
    if (!$alert) {
        $allAlerts = Alert::where('product_id', $product->id)->get();
        if ($allAlerts->count() > 0) {
            echo "  ⚠️  Alertes trouvées (mais ne correspondent pas) :\n";
            foreach ($allAlerts as $a) {
                echo "     - Type: {$a->type}, Sévérité: {$a->severity}, Message: {$a->message}\n";
            }
        }
    }

    if ($alert) {
        echo "  ✅ Alerte créée : {$alert->type} ({$alert->severity})\n";
        echo "     Message : {$alert->message}\n";
        
        // Compter les utilisateurs qui recevront l'email
        $usersCount = User::where('store_id', $store->id)
            ->whereNotNull('email_verified_at')
            ->count();
        
        echo "     📧 {$usersCount} utilisateur(s) recevront un email\n";
        
        $results[] = [
            'scenario' => $scenario['name'],
            'status' => '✅ Succès',
            'alert_created' => true,
            'alert_type' => $alert->type,
            'alert_severity' => $alert->severity,
            'users_count' => $usersCount,
        ];
    } else {
        echo "  ❌ Aucune alerte créée (attendu: {$scenario['expected_alert']}, {$scenario['expected_severity']})\n";
        $results[] = [
            'scenario' => $scenario['name'],
            'status' => '❌ Échec',
            'alert_created' => false,
        ];
    }
    
    echo "\n";
}

// Résumé
echo "📊 Résumé des tests :\n\n";
echo str_pad("Scénario", 40) . " | " . str_pad("Statut", 10) . " | " . str_pad("Alerte", 8) . " | " . str_pad("Type", 12) . " | " . str_pad("Sévérité", 10) . " | " . "Emails\n";
echo str_repeat("-", 100) . "\n";

foreach ($results as $result) {
    $alertCreated = $result['alert_created'] ? 'Oui' : 'Non';
    $type = $result['alert_type'] ?? '-';
    $severity = $result['alert_severity'] ?? '-';
    $emails = isset($result['users_count']) ? $result['users_count'] : '-';
    
    echo str_pad($result['scenario'], 40) . " | " . 
         str_pad($result['status'], 10) . " | " . 
         str_pad($alertCreated, 8) . " | " . 
         str_pad($type, 12) . " | " . 
         str_pad($severity, 10) . " | " . 
         $emails . "\n";
}

echo "\n";

// Option pour nettoyer
echo "💡 Pour supprimer les produits de test, exécutez :\n";
echo "   DELETE FROM products WHERE name LIKE 'Produit Test - %';\n";
echo "   DELETE FROM alerts WHERE product_id IN (SELECT id FROM products WHERE name LIKE 'Produit Test - %');\n\n";

