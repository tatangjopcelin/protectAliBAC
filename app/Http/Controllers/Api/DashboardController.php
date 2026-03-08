<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Policies\DashboardPolicy;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Alert;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Récupère toutes les statistiques du tableau de bord
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        
        // Vérifier que l'utilisateur est authentifié (le middleware auth:sanctum devrait déjà le faire)
        if (!$user) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        // Toujours recharger l'utilisateur depuis la BDD pour avoir le store_id à jour (évite stats d'un autre établissement)
        $user = $user->fresh(['store']);
        if (!$user) {
            return response()->json(['message' => 'Utilisateur introuvable'], 401);
        }
        
        // Vérifier les permissions
        if (!(new DashboardPolicy())->viewStats($user)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        
        $period = $request->get('period', 'month'); // day, week, month, year

        $payload = [
            'overview' => $this->getOverview($user),
            'stock_value' => $this->getStockValue($user),
            'waste' => $this->getWasteStats($period, $user),
            'consumption' => $this->getConsumptionStats($period, $user),
            'top_products' => $this->getTopProducts($period, $user),
            'expiring_products' => $this->getExpiringProducts($user),
            'low_stock_products' => $this->getLowStockProducts($user),
            'alerts_summary' => $this->getAlertsSummary($user),
        ];
        // Inclure l'établissement pour lequel les stats ont été calculées (une seule source de vérité côté front)
        $payload['store'] = $user->store ? [
            'id' => $user->store->id,
            'name' => $user->store->name,
        ] : null;

        return response()->json($payload);
    }

    /**
     * Vue d'ensemble générale (strictement filtrée par établissement)
     */
    private function getOverview($user): array
    {
        if (!$user->store_id) {
            return [
                'total_products' => 0,
                'total_stock_value' => 0,
                'expiring_today' => 0,
                'expiring_tomorrow' => 0,
                'low_stock_count' => 0,
                'expired_count' => 0,
            ];
        }
        $productsQuery = Product::where('is_active', true)
            ->whereHas('zone', function($q) use ($user) {
                $q->where('store_id', $user->store_id);
            });

        return [
            'total_products' => (clone $productsQuery)->count(),
            'total_stock_value' => $this->getStockValue($user)['total'],
            'expiring_today' => (clone $productsQuery)
                ->where('expiration_date', Carbon::today())
                ->where('quantity', '>', 0)
                ->count(),
            'expiring_tomorrow' => (clone $productsQuery)
                ->where('expiration_date', Carbon::tomorrow())
                ->where('quantity', '>', 0)
                ->count(),
            'low_stock_count' => (clone $productsQuery)
                ->where(function ($query) {
                    // Les deux critères "stock bas" dans un même bloc pour garder le filtre zone
                    $query->where(function ($q) {
                        $q->where('min_quantity', '>', 0)
                          ->whereColumn('quantity', '<=', 'min_quantity');
                    })
                    ->orWhere(function ($q) {
                        $q->where(function ($q2) {
                            $q2->whereNull('min_quantity')->orWhere('min_quantity', '<=', 0);
                        })->where('quantity', '<=', \App\Models\Product::LOW_STOCK_DEFAULT_THRESHOLD);
                    });
                })
                ->count(),
            // Périmés : date dépassée ou aujourd'hui (aligné avec la page Produits périmés)
            'expired_count' => (clone $productsQuery)
                ->where(function ($q) {
                    $q->where('status', 'expired')
                      ->orWhere('expiration_date', '<=', Carbon::today());
                })
                ->count(),
        ];
    }

    /**
     * Valeur totale du stock (strictement filtrée par établissement)
     */
    private function getStockValue($user): array
    {
        if (!$user->store_id) {
            return ['total' => 0, 'by_category' => [], 'currency' => 'EUR'];
        }
        $products = Product::where('is_active', true)
            ->whereNotNull('purchase_price')
            ->whereHas('zone', function($q) use ($user) {
                $q->where('store_id', $user->store_id);
            })
            ->get();

        $total = 0;
        $byCategory = [];

        foreach ($products as $product) {
            $value = $product->quantity * $product->purchase_price;
            $total += $value;

            $categoryName = $product->category->name ?? 'Non catégorisé';
            if (!isset($byCategory[$categoryName])) {
                $byCategory[$categoryName] = 0;
            }
            $byCategory[$categoryName] += $value;
        }

        return [
            'total' => round($total, 2),
            'by_category' => $byCategory,
            'currency' => 'EUR',
        ];
    }

    /**
     * Statistiques de gaspillage (strictement filtrées par établissement)
     */
    private function getWasteStats(string $period, $user): array
    {
        if (!$user->store_id) {
            return [
                'total_value' => 0, 'total_quantity' => 0, 'period' => $period,
                'top_wasted_products' => [], 'waste_by_category' => [],
            ];
        }
        $dateFrom = $this->getDateFrom($period);

        $wastedMovements = StockMovement::where('type', 'wasted')
            ->where('created_at', '>=', $dateFrom)
            ->whereHas('product.zone', function($q) use ($user) {
                $q->where('store_id', $user->store_id);
            })
            ->with('product')
            ->get();

        $totalWaste = 0;
        $wasteByProduct = [];
        $wasteByCategory = [];
        $totalQuantity = 0;

        foreach ($wastedMovements as $movement) {
            $product = $movement->product;
            if (!$product) continue;

            $wasteValue = $movement->quantity * ($product->purchase_price ?? 0);
            $totalWaste += $wasteValue;
            $totalQuantity += $movement->quantity;

            // Par produit
            $productName = $product->name;
            if (!isset($wasteByProduct[$productName])) {
                $wasteByProduct[$productName] = [
                    'quantity' => 0,
                    'value' => 0,
                    'count' => 0,
                ];
            }
            $wasteByProduct[$productName]['quantity'] += $movement->quantity;
            $wasteByProduct[$productName]['value'] += $wasteValue;
            $wasteByProduct[$productName]['count']++;

            // Par catégorie
            $categoryName = $product->category->name ?? 'Non catégorisé';
            if (!isset($wasteByCategory[$categoryName])) {
                $wasteByCategory[$categoryName] = [
                    'quantity' => 0,
                    'value' => 0,
                ];
            }
            $wasteByCategory[$categoryName]['quantity'] += $movement->quantity;
            $wasteByCategory[$categoryName]['value'] += $wasteValue;
        }

        // Trier par valeur décroissante
        arsort($wasteByProduct);
        arsort($wasteByCategory);

        return [
            'total_value' => round($totalWaste, 2),
            'total_quantity' => round($totalQuantity, 3),
            'period' => $period,
            'top_wasted_products' => array_slice($wasteByProduct, 0, 10, true),
            'waste_by_category' => $wasteByCategory,
        ];
    }

    /**
     * Statistiques de consommation (strictement filtrées par établissement)
     */
    private function getConsumptionStats(string $period, $user): array
    {
        if (!$user->store_id) {
            return [
                'total_value' => 0, 'period' => $period,
                'top_consumed_products' => [], 'consumption_by_category' => [],
            ];
        }
        $dateFrom = $this->getDateFrom($period);

        $consumptionMovements = StockMovement::whereIn('type', ['used', 'exit'])
            ->where('created_at', '>=', $dateFrom)
            ->whereHas('product.zone', function($q) use ($user) {
                $q->where('store_id', $user->store_id);
            })
            ->with('product.category')
            ->get();

        $totalConsumption = 0;
        $consumptionByProduct = [];
        $consumptionByCategory = [];

        foreach ($consumptionMovements as $movement) {
            $product = $movement->product;
            if (!$product) continue;

            $consumptionValue = $movement->quantity * ($product->purchase_price ?? 0);
            $totalConsumption += $consumptionValue;

            // Par produit
            $productName = $product->name;
            if (!isset($consumptionByProduct[$productName])) {
                $consumptionByProduct[$productName] = [
                    'quantity' => 0,
                    'value' => 0,
                    'count' => 0,
                ];
            }
            $consumptionByProduct[$productName]['quantity'] += $movement->quantity;
            $consumptionByProduct[$productName]['value'] += $consumptionValue;
            $consumptionByProduct[$productName]['count']++;

            // Par catégorie
            $categoryName = $product->category->name ?? 'Non catégorisé';
            if (!isset($consumptionByCategory[$categoryName])) {
                $consumptionByCategory[$categoryName] = [
                    'quantity' => 0,
                    'value' => 0,
                ];
            }
            $consumptionByCategory[$categoryName]['quantity'] += $movement->quantity;
            $consumptionByCategory[$categoryName]['value'] += $consumptionValue;
        }

        // Trier par valeur décroissante
        arsort($consumptionByProduct);
        arsort($consumptionByCategory);

        return [
            'total_value' => round($totalConsumption, 2),
            'period' => $period,
            'top_consumed_products' => array_slice($consumptionByProduct, 0, 10, true),
            'consumption_by_category' => $consumptionByCategory,
        ];
    }

    /**
     * Top produits (utilisés et jetés) — strictement filtrés par établissement
     */
    private function getTopProducts(string $period, $user): array
    {
        if (!$user->store_id) {
            return ['most_used' => [], 'most_wasted' => []];
        }
        $dateFrom = $this->getDateFrom($period);

        $usedProducts = StockMovement::where('type', 'used')
            ->where('created_at', '>=', $dateFrom)
            ->where('store_id', $user->store_id)
            ->select('product_id', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('product_id')
            ->orderBy('total_quantity', 'desc')
            ->limit(10)
            ->with('product')
            ->get();

        $wastedProducts = StockMovement::where('type', 'wasted')
            ->where('created_at', '>=', $dateFrom)
            ->where('store_id', $user->store_id)
            ->select('product_id', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('product_id')
            ->orderBy('total_quantity', 'desc')
            ->limit(10)
            ->with('product')
            ->get();

        return [
            'most_used' => $usedProducts->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? 'Produit supprimé',
                    'quantity' => round($item->total_quantity, 3),
                ];
            }),
            'most_wasted' => $wastedProducts->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? 'Produit supprimé',
                    'quantity' => round($item->total_quantity, 3),
                ];
            }),
        ];
    }

    /**
     * Produits expirant bientôt (strictement filtrés par établissement)
     */
    private function getExpiringProducts($user): array
    {
        $emptyExpiring = [
            'today' => [],
            'tomorrow' => [],
            'next_7_days' => [],
        ];
        if (!$user->store_id) {
            return $emptyExpiring;
        }
        $productsQuery = Product::where('is_active', true)
            ->whereHas('zone', function($q) use ($user) {
                $q->where('store_id', $user->store_id);
            });

        // À la date de péremption (aujourd'hui) le produit est "périmé", pas "expire bientôt" → today = []
        return [
            'today' => [],
            'tomorrow' => (clone $productsQuery)
                ->where('expiration_date', Carbon::tomorrow())
                ->where('quantity', '>', 0)
                ->with(['category', 'zone.store'])
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'quantity' => $product->quantity,
                        'unit' => $product->unit,
                        'expiration_date' => $product->expiration_date->format('Y-m-d'),
                        'zone' => $product->zone->name ?? null,
                        'store' => $product->zone->store->name ?? null,
                    ];
                })
                ->values()
                ->toArray(),
            'next_7_days' => (clone $productsQuery)
                ->where('expiration_date', '>', Carbon::tomorrow())
                ->where('expiration_date', '<=', Carbon::now()->addDays(7))
                ->where('quantity', '>', 0)
                ->with(['category', 'zone.store'])
                ->orderBy('expiration_date')
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'quantity' => $product->quantity,
                        'unit' => $product->unit,
                        'expiration_date' => $product->expiration_date->format('Y-m-d'),
                        'days_until_expiration' => method_exists($product, 'daysUntilExpiration') ? $product->daysUntilExpiration() : null,
                        'zone' => $product->zone->name ?? null,
                        'store' => $product->zone->store->name ?? null,
                    ];
                })
                ->values()
                ->toArray(),
        ];
    }

    /**
     * Produits en stock bas (strictement filtrés par établissement)
     */
    private function getLowStockProducts($user): array
    {
        if (!$user->store_id) {
            return [];
        }
        return Product::where('is_active', true)
            ->whereHas('zone', function($q) use ($user) {
                $q->where('store_id', $user->store_id);
            })
            ->where(function ($query) {
                $query->where('min_quantity', '>', 0)
                      ->whereColumn('quantity', '<=', 'min_quantity');
                $query->orWhere(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNull('min_quantity')->orWhere('min_quantity', '<=', 0);
                    })->where('quantity', '<=', \App\Models\Product::LOW_STOCK_DEFAULT_THRESHOLD);
                });
            })
            ->with(['category', 'supplier', 'zone.store'])
            ->orderBy('quantity', 'asc')
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'quantity' => $product->quantity,
                    'min_quantity' => $product->min_quantity,
                    'unit' => $product->unit,
                    'supplier' => $product->supplier->name ?? null,
                    'zone' => $product->zone->name ?? null,
                    'store' => $product->zone->store->name ?? null,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Résumé des alertes
     */
    private function getAlertsSummary($user): array
    {
        if (!$user->store_id) {
            return ['total_unread' => 0, 'by_type' => [], 'by_severity' => []];
        }
        $alertsQuery = Alert::where('is_read', false)
            ->where('store_id', $user->store_id);

        return [
            'total_unread' => (clone $alertsQuery)->count(),
            'by_type' => (clone $alertsQuery)
                ->select('type', DB::raw('count(*) as count'))
                ->groupBy('type')
                ->get()
                ->pluck('count', 'type'),
            'by_severity' => (clone $alertsQuery)
                ->select('severity', DB::raw('count(*) as count'))
                ->groupBy('severity')
                ->get()
                ->pluck('count', 'severity'),
        ];
    }

    /**
     * Calcule la date de début selon la période
     */
    private function getDateFrom(string $period): Carbon
    {
        return match ($period) {
            'day' => Carbon::today(),
            'week' => Carbon::now()->startOfWeek(),
            'month' => Carbon::now()->startOfMonth(),
            'year' => Carbon::now()->startOfYear(),
            default => Carbon::now()->startOfMonth(),
        };
    }
}
