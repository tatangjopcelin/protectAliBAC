<?php

namespace App\Policies;

use App\Models\ShoppingListItem;
use App\Models\User;

class ShoppingListItemPolicy
{
    /**
     * Vérifier si l'utilisateur est le créateur de l'item
     */
    private function isCreator(User $user, ShoppingListItem $item): bool
    {
        return $item->added_by === $user->id;
    }

    /**
     * Vérifier si l'utilisateur peut modifier (admin, chef, directeur ou créateur)
     */
    private function canModify(User $user, ShoppingListItem $item): bool
    {
        // Admin, Chef et Directeur peuvent modifier n'importe quel item
        if ($user->isAdmin() || $user->isChef() || $user->isDirector()) {
            return true;
        }
        
        // Vérifier si l'utilisateur est le créateur de l'item
        return $this->isCreator($user, $item);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Tous les utilisateurs authentifiés peuvent voir la liste de courses
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ShoppingListItem $shoppingListItem): bool
    {
        // Tous les utilisateurs authentifiés peuvent voir un item
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Tous les utilisateurs authentifiés peuvent créer des items
        // Pas de restriction de rôle
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ShoppingListItem $shoppingListItem): bool
    {
        // Admin, Chef, Directeur ou créateur peuvent modifier
        return $this->canModify($user, $shoppingListItem);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ShoppingListItem $shoppingListItem): bool
    {
        // Admin, Chef, Directeur ou créateur peuvent supprimer
        return $this->canModify($user, $shoppingListItem);
    }
}
