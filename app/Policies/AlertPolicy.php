<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

class AlertPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // Tous les rôles peuvent voir les alertes
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Alert $alert): bool
    {
        // Tous les rôles peuvent voir une alerte
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Seul le système peut créer des alertes (automatiquement)
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Alert $alert): bool
    {
        // Admin, Chef et Directeur peuvent modifier les alertes
        return $user->isAdmin() || $user->isChef() || $user->isDirector();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Alert $alert): bool
    {
        // Admin et Chef peuvent supprimer des alertes
        return $user->isAdmin() || $user->isChef();
    }

    /**
     * Determine whether the user can mark alert as read.
     */
    public function markAsRead(User $user, Alert $alert): bool
    {
        // Tous les rôles peuvent marquer leurs alertes comme lues
        return true;
    }
}
