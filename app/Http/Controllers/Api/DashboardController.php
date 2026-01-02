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
        
        // Vérifier les permissions
        if (!(new DashboardPolicy())->viewStats($user)) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }
        
        $period = $request->get('period', 'month'); // day, week, month, year

        return response()->json([
            'overview' => $this->getOverview($user),
            'stock_value' => $this->getStockValue($user),
            'waste' => $this->getWasteStats($period, $user),
            'consumption' => $this->getConsumptionStats($period, $user),
            'top_products' => $this->getTopProducts($period, $user),
            'expiring_products' => $this->getExpiringProducts($user),
            'low_stock_products' => $this->getLowStockProducts($user),
            'alerts_summary' => $this->getAlertsSummary($user),
        ]);
    }

    /**
     * Vue d'ensemble générale
     */
    private function getOverview($user): array
    {
        $productsQuery = Product::where('is_active', true)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
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
                    // Produits avec min_quantity défini et quantity <= min_quantity
                    $query->whereNotNull('min_quantity')
                          ->whereColumn('quantity', '<=', 'min_quantity');
                })
                ->orWhere(function ($query) {
                    // Produits sans min_quantity mais avec quantity = 0 (stock épuisé)
                    $query->whereNull('min_quantity')
                          ->where('quantity', '<=', 0);
                })
                ->count(),
            'expired_count' => (clone $productsQuery)
                ->where('status', 'expired')
                ->where('quantity', '>', 0)
                ->count(),
        ];
    }

    /**
     * Valeur totale du stock
     */
    private function getStockValue($user): array
    {
        $products = Product::where('is_active', true)
            ->whereNotNull('purchase_price')
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
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
     * Statistiques de gaspillage
     */
    private function getWasteStats(string $period, $user): array
    {
        $dateFrom = $this->getDateFrom($period);

        $wastedMovements = StockMovement::where('type', 'wasted')
            ->where('created_at', '>=', $dateFrom)
            ->whereHas('product.zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
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
     * Statistiques de consommation
     */
    private function getConsumptionStats(string $period, $user): array
    {
        $dateFrom = $this->getDateFrom($period);

        $consumptionMovements = StockMovement::whereIn('type', ['used', 'exit'])
            ->where('created_at', '>=', $dateFrom)
            ->whereHas('product.zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
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
     * Top produits (utilisés et jetés)
     */
    private function getTopProducts(string $period, $user): array
    {
        $dateFrom = $this->getDateFrom($period);

        $usedProductsQuery = StockMovement::where('type', 'used')
            ->where('created_at', '>=', $dateFrom);
        if ($user->store_id) {
            $usedProductsQuery->where('store_id', $user->store_id);
        }
        $usedProducts = $usedProductsQuery->select('product_id', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('product_id')
            ->orderBy('total_quantity', 'desc')
            ->limit(10)
            ->with('product')
            ->get();

        $wastedProductsQuery = StockMovement::where('type', 'wasted')
            ->where('created_at', '>=', $dateFrom);
        if ($user->store_id) {
            $wastedProductsQuery->where('store_id', $user->store_id);
        }
        $wastedProducts = $wastedProductsQuery->select('product_id', DB::raw('SUM(quantity) as total_quantity'))
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
     * Produits expirant bientôt
     */
    private function getExpiringProducts($user): array
    {
        $productsQuery = Product::where('is_active', true)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            });

        return [
            'today' => (clone $productsQuery)
                ->where('expiration_date', Carbon::today())
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
            'next_7_days' => Product::where('is_active', true)
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
     * Produits en stock bas
     */
    private function getLowStockProducts($user): array
    {
        return Product::where('is_active', true)
            ->whereHas('zone', function($q) use ($user) {
                if ($user->store_id) {
                    $q->where('store_id', $user->store_id);
                }
            })
            ->where(function ($query) {
                // Produits avec min_quantity défini et quantity <= min_quantity
                $query->whereNotNull('min_quantity')
                      ->whereColumn('quantity', '<=', 'min_quantity');
            })
            ->orWhere(function ($query) {
                // Produits sans min_quantity mais avec quantity = 0 (stock épuisé)
                $query->whereNull('min_quantity')
                      ->where('quantity', '<=', 0);
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
        $alertsQuery = Alert::where('is_read', false);
        if ($user->store_id) {
            $alertsQuery->where('store_id', $user->store_id);
        }

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
