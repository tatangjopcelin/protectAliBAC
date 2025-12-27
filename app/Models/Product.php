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
        // Champs de traçabilité complète
        'batch_number',
        'manufacturing_date',
        'factory_name',
        'factory_address',
        'factory_contact_person',
        'factory_phone',
        'factory_email',
        'origin_country',
        'origin_region',
        'certificate_number',
        'certificate_type',
        'certificate_issue_date',
        'certificate_expiry_date',
        'certificate_file_path',
        'import_document_number',
        'import_date',
        'customs_declaration_number',
        'transport_method',
        'transport_company',
        'transport_document_number',
        'storage_temperature',
        'storage_conditions',
        'serial_number',
        'supplier_reception_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'min_quantity' => 'decimal:3',
        'purchase_price' => 'decimal:2',
        'reception_date' => 'date',
        'expiration_date' => 'date',
        'is_active' => 'boolean',
        // Dates de traçabilité
        'manufacturing_date' => 'date',
        'certificate_issue_date' => 'date',
        'certificate_expiry_date' => 'date',
        'import_date' => 'date',
        'supplier_reception_date' => 'date',
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
