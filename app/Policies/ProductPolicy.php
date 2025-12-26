<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Tous les rôles peuvent voir les produits
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Product $product): bool
    {
        // Tous les rôles peuvent voir un produit
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Admin, Chef, Directeur, Magasinier peuvent créer des produits
        return $user->isAdmin() || $user->isChef() || $user->isDirector() || $user->isStorekeeper();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Product $product): bool
    {
        // Admin, Chef, Directeur, Magasinier peuvent modifier des produits
        return $user->isAdmin() || $user->isChef() || $user->isDirector() || $user->isStorekeeper();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Product $product): bool
    {
        // Admin, Chef et Directeur peuvent supprimer des produits
        return $user->isAdmin() || $user->isChef() || $user->isDirector();
    }

    /**
     * Determine whether the user can add stock.
     */
    public function addStock(User $user, Product $product): bool
    {
        // Admin, Chef, Directeur, Magasinier peuvent ajouter du stock
        return $user->isAdmin() || $user->isChef() || $user->isDirector() || $user->isStorekeeper();
    }

    /**
     * Determine whether the user can mark as expired.
     */
    public function markExpired(User $user, Product $product): bool
    {
        // Admin, Chef, Directeur, Cuisinier, Boucher, Magasinier peuvent marquer comme périmé
        return $user->isAdmin() || $user->isChef() || $user->isDirector() || $user->isCook() || $user->isButcher() || $user->isStorekeeper();
    }
}
