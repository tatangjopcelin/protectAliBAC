<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Admin, Chef, Directeur, Magasinier, Comptable peuvent voir les commandes
        return $user->isAdmin() || $user->isChef() || $user->isDirector() || $user->isStorekeeper() || $user->isAccountant();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Order $order): bool
    {
        // Admin, Chef, Magasinier, Comptable peuvent voir une commande
        return $user->isAdmin() || $user->isChef() || $user->isStorekeeper() || $user->isAccountant();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Admin, Chef, Directeur, Magasinier peuvent créer des commandes
        return $user->isAdmin() || $user->isChef() || $user->isDirector() || $user->isStorekeeper();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Order $order): bool
    {
        // Admin, Chef, Magasinier peuvent modifier les commandes
        return $user->isAdmin() || $user->isChef() || $user->isStorekeeper();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Order $order): bool
    {
        // Admin, Chef et Directeur peuvent supprimer des commandes
        return $user->isAdmin() || $user->isChef() || $user->isDirector();
    }

    /**
     * Determine whether the user can generate auto orders.
     */
    public function generateAutoOrder(User $user): bool
    {
        // Admin, Chef, Magasinier peuvent générer des commandes automatiques
        return $user->isAdmin() || $user->isChef() || $user->isStorekeeper();
    }
}
