<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPriceHistory extends Model
{
    protected $fillable = [
        'supplier_id',
        'product_id',
        'product_name',
        'price',
        'unit',
        'effective_date',
        'notes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'effective_date' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
