<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Vérifier si l'utilisateur est le créateur du produit
     */
    private function isCreator(User $user, Product $product): bool
    {
        $firstTrace = \App\Models\ProductTrace::where('product_id', $product->id)
            ->where('action', 'created')
            ->orderBy('created_at', 'asc')
            ->first();
        
        return $firstTrace && $firstTrace->user_id === $user->id;
    }

    /**
     * Vérifier si l'utilisateur peut modifier (admin, chef, directeur ou créateur)
     */
    private function canModify(User $user, Product $product): bool
    {
        // Admin, Chef et Directeur peuvent modifier n'importe quel produit
        if ($user->isAdmin() || $user->isChef() || $user->isDirector()) {
            return true;
        }
        
        // Vérifier si l'utilisateur est le créateur du produit
        return $this->isCreator($user, $product);
    }

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
        // Tous les rôles authentifiés peuvent créer des produits (fonctionnalité de base)
        // Admin, Chef, Directeur, Magasinier, Boucher, Cuisinier peuvent créer des produits
        return $user->isAdmin() || $user->isChef() || $user->isDirector() || $user->isStorekeeper() || $user->isButcher() || $user->isCook();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Product $product): bool
    {
        // Seul l'admin ou le créateur peuvent modifier un produit
        return $this->canModify($user, $product);
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
        // Seul l'admin ou le créateur peuvent ajouter du stock
        return $this->canModify($user, $product);
    }

    /**
     * Determine whether the user can mark as expired.
     */
    public function markExpired(User $user, Product $product): bool
    {
        // Seul l'admin ou le créateur peuvent marquer comme périmé
        return $this->canModify($user, $product);
    }

    /**
     * Determine whether the user can reduce stock.
     */
    public function reduceStock(User $user, Product $product): bool
    {
        // Seul l'admin ou le créateur peuvent réduire le stock
        return $this->canModify($user, $product);
    }
}
