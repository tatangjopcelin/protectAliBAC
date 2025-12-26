<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Product extends Model
{
    protected $fillable = [
        'name',
        'category_id',
        'supplier_id',
        'zone_id',
        'quantity',
        'unit',
        'min_quantity',
        'reception_date',
        'expiration_date',
        'purchase_price',
        'photo',
        'barcode',
        'notes',
        'status',
        'is_active',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'min_quantity' => 'decimal:3',
        'purchase_price' => 'decimal:2',
        'reception_date' => 'date',
        'expiration_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function traces(): HasMany
    {
        return $this->hasMany(ProductTrace::class);
    }

    // Méthodes utilitaires
    public function updateStatus(): void
    {
        $today = Carbon::today();
        $expirationDate = Carbon::parse($this->expiration_date);
        
        if ($expirationDate->isPast()) {
            $this->status = 'expired';
        } elseif ($expirationDate->isToday() || $expirationDate->isTomorrow()) {
            $this->status = 'warning';
        } else {
            $this->status = 'ok';
        }
        
        $this->save();
    }

    public function isExpired(): bool
    {
        return Carbon::parse($this->expiration_date)->isPast();
    }

    public function daysUntilExpiration(): int
    {
        return Carbon::today()->diffInDays(Carbon::parse($this->expiration_date), false);
    }

    public function isLowStock(): bool
    {
        return $this->quantity <= $this->min_quantity;
    }
}
