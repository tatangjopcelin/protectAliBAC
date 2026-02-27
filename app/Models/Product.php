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
        // Champs de traçabilité (5 informations essentielles)
        'batch_number',           // 1. Numéro de lot
        'manufacturing_date',     // 2. Date de fabrication
        'factory_name',           // 3. Nom de l'usine
        'origin_country',         // 4. Pays d'origine
        'certificate_number',     // 5. Numéro de certificat
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'min_quantity' => 'decimal:3',
        'purchase_price' => 'decimal:2',
        'reception_date' => 'date',
        'expiration_date' => 'date',
        'is_active' => 'boolean',
        // Date de traçabilité
        'manufacturing_date' => 'date',
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

    /**
     * Calcule le statut à partir de la date de péremption (sans sauvegarder).
     * Utilisé pour l'affichage (liste produits) afin que le statut soit toujours à jour.
     */
    public function getComputedStatus(): string
    {
        if (!$this->expiration_date) {
            return $this->status ?? 'ok';
        }
        $expirationDate = Carbon::parse($this->expiration_date);
        if ($expirationDate->isPast()) {
            return 'expired';
        }
        if ($expirationDate->isToday() || $expirationDate->isTomorrow()) {
            return 'warning';
        }
        return 'ok';
    }

    public function updateStatus(): void
    {
        $this->status = $this->getComputedStatus();
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

    /** Seuil par défaut pour "stock bas" quand min_quantity n'est pas défini (3, 2, 1, 0). */
    public const LOW_STOCK_DEFAULT_THRESHOLD = 3;

    public function isLowStock(): bool
    {
        // Seuil réel uniquement si min_quantity est défini et > 0 (sinon 0 = "non renseigné", on utilise 3)
        if ($this->min_quantity !== null && $this->min_quantity !== '' && (float) $this->min_quantity > 0) {
            return $this->quantity <= $this->min_quantity;
        }
        return $this->quantity <= self::LOW_STOCK_DEFAULT_THRESHOLD;
    }
}
