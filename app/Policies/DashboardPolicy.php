<?php

namespace App\Policies;

use App\Models\User;

class DashboardPolicy
{
    /**
     * Determine whether the user can view dashboard statistics.
     */
    public function viewStats(User $user): bool
    {
        // Tous les rôles peuvent voir les statistiques
        return true;
    }

    /**
     * Determine whether the user can view financial reports.
     */
    public function viewFinancialReports(User $user): bool
    {
        // Admin, Chef, Directeur et Comptable peuvent voir les rapports financiers
        return $user->isAdmin() || $user->isChef() || $user->isDirector() || $user->isAccountant();
    }

    /**
     * Determine whether the user can export data.
     */
    public function exportData(User $user): bool
    {
        // Admin, Chef, Comptable peuvent exporter les données
        return $user->isAdmin() || $user->isChef() || $user->isAccountant();
    }
}
