<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'name',
        'description',
        'instructions',
        'servings',
        'cost',
        'photo',
        'is_active',
    ];

    protected $casts = [
        'servings' => 'integer',
        'cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function calculateCost(): float
    {
        $totalCost = 0;
        foreach ($this->ingredients as $ingredient) {
            $product = $ingredient->product;
            if ($product && $product->purchase_price) {
                $totalCost += ($product->purchase_price * $ingredient->quantity);
            }
        }
        $this->cost = $totalCost;
        $this->save();
        return $totalCost;
    }
}
