<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingListItem extends Model
{
    protected $fillable = [
        'name', // Nom du produit à acheter
        'quantity',
        'unit',
        'category_id',
        'product_id',
        'priority',
        'status',
        'added_by', // Créateur de l'item
        'ordered_by',
        'notes',
        'ordered_at',
        'received_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /**
     * Vérifie si l'item est en attente
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Vérifie si l'item est commandé
     */
    public function isOrdered(): bool
    {
        return $this->status === 'ordered';
    }

    /**
     * Vérifie si l'item est reçu
     */
    public function isReceived(): bool
    {
        return $this->status === 'received';
    }

    /**
     * Obtenir la couleur de la priorité
     */
    public function getPriorityColor(): string
    {
        return match($this->priority) {
            'urgent' => '#dc3545', // Rouge
            'high' => '#fd7e14',   // Orange
            'medium' => '#ffc107', // Jaune
            'low' => '#28a745',    // Vert
            default => '#6c757d',  // Gris
        };
    }
}
