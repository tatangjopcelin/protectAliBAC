<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Génère automatiquement une commande basée sur les produits en stock bas
     */
    public function generateOrderForSupplier(int $supplierId, array $productIds = []): Order
    {
        $supplier = Supplier::findOrFail($supplierId);

        // Si aucun produit spécifié, prendre tous les produits de ce fournisseur en stock bas
        if (empty($productIds)) {
            $products = Product::where('supplier_id', $supplierId)
                ->where('is_active', true)
                ->whereColumn('quantity', '<=', 'min_quantity')
                ->get();
        } else {
            $products = Product::where('supplier_id', $supplierId)
                ->whereIn('id', $productIds)
                ->where('is_active', true)
                ->get();
        }

        if ($products->isEmpty()) {
            throw new \Exception('Aucun produit à commander pour ce fournisseur');
        }

        // Créer la commande
        $order = Order::create([
            'supplier_id' => $supplierId,
            'order_number' => $this->generateOrderNumber(),
            'status' => 'draft',
            'order_date' => Carbon::today(),
            'total_amount' => 0,
        ]);

        $totalAmount = 0;

        // Créer les items de commande
        foreach ($products as $product) {
            // Calculer la quantité à commander (stock minimum * 2 - stock actuel)
            $quantityToOrder = max(
                ($product->min_quantity * 2) - $product->quantity,
                $product->min_quantity
            );

            // Utiliser le dernier prix d'achat ou le prix actuel
            $unitPrice = $product->purchase_price ?? 0;

            $item = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $quantityToOrder,
                'unit' => $product->unit,
                'unit_price' => $unitPrice,
                'total_price' => $quantityToOrder * $unitPrice,
            ]);

            $totalAmount += $item->total_price;
        }

        // Mettre à jour le total de la commande
        $order->update(['total_amount' => $totalAmount]);

        return $order->load(['items', 'supplier']);
    }

    /**
     * Génère un numéro de commande unique
     */
    public function generateOrderNumber(): string
    {
        $date = Carbon::now()->format('Ymd');
        $lastOrder = Order::whereDate('created_at', Carbon::today())
            ->orderBy('id', 'desc')
            ->first();

        if ($lastOrder) {
            $lastNumber = (int) substr($lastOrder->order_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return 'CMD-' . $date . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Compare les prix entre différents fournisseurs pour un produit
     */
    public function compareSupplierPrices(int $productId): array
    {
        $product = Product::findOrFail($productId);

        // Trouver tous les fournisseurs qui ont ce produit
        $suppliers = Supplier::whereHas('products', function ($query) use ($productId) {
            $query->where('products.id', $productId);
        })->with(['products' => function ($query) use ($productId) {
            $query->where('products.id', $productId);
        }])->get();

        $comparison = [];

        foreach ($suppliers as $supplier) {
            $supplierProduct = $supplier->products->first();
            if ($supplierProduct && $supplierProduct->purchase_price) {
                $comparison[] = [
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'price' => $supplierProduct->purchase_price,
                    'unit' => $supplierProduct->unit,
                    'last_order_date' => $this->getLastOrderDate($supplier->id, $productId),
                ];
            }
        }

        // Trier par prix
        usort($comparison, function ($a, $b) {
            return $a['price'] <=> $b['price'];
        });

        return $comparison;
    }

    /**
     * Récupère la date de la dernière commande pour un produit d'un fournisseur
     */
    private function getLastOrderDate(int $supplierId, int $productId): ?string
    {
        $order = Order::where('supplier_id', $supplierId)
            ->whereHas('items', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })
            ->where('status', '!=', 'cancelled')
            ->orderBy('order_date', 'desc')
            ->first();

        return $order ? $order->order_date->format('Y-m-d') : null;
    }
}

