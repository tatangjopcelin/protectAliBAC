<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'supplier_id',
        'store_id',
        'user_id',
        'order_number',
        'status',
        'order_date',
        'expected_delivery_date',
        'delivery_date',
        'total_amount',
        'notes',
        'supplier_token',
        'supplier_token_expires_at',
        'supplier_responded_at',
        'supplier_response_seen_at',
        'supplier_response_note',
        'supplier_confirmation_note',
        'delivery_photo',
        'supplier_delivery_signature',
        'establishment_delivery_signature',
        'delivery_received_by_user_id',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'delivery_date' => 'date',
        'total_amount' => 'decimal:2',
        'supplier_token_expires_at' => 'datetime',
        'supplier_responded_at' => 'datetime',
        'supplier_response_seen_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function deliveryReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivery_received_by_user_id');
    }
}
