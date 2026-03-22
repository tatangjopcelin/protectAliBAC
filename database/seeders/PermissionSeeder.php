<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;
use App\Models\RolePermission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // Produits
            ['name' => 'products.view', 'resource' => 'products', 'action' => 'view', 'description' => 'Voir les produits'],
            ['name' => 'products.create', 'resource' => 'products', 'action' => 'create', 'description' => 'Créer des produits'],
            ['name' => 'products.update', 'resource' => 'products', 'action' => 'update', 'description' => 'Modifier des produits'],
            ['name' => 'products.delete', 'resource' => 'products', 'action' => 'delete', 'description' => 'Supprimer des produits'],
            ['name' => 'products.add_stock', 'resource' => 'products', 'action' => 'add_stock', 'description' => 'Ajouter du stock'],
            ['name' => 'products.mark_expired', 'resource' => 'products', 'action' => 'mark_expired', 'description' => 'Marquer comme périmé'],

            // Alertes
            ['name' => 'alerts.view', 'resource' => 'alerts', 'action' => 'view', 'description' => 'Voir les alertes'],
            ['name' => 'alerts.manage', 'resource' => 'alerts', 'action' => 'manage', 'description' => 'Gérer les alertes'],
            ['name' => 'alerts.delete', 'resource' => 'alerts', 'action' => 'delete', 'description' => 'Supprimer les alertes'],

            // Dashboard
            ['name' => 'dashboard.view', 'resource' => 'dashboard', 'action' => 'view', 'description' => 'Voir le tableau de bord'],
            ['name' => 'dashboard.view_financial', 'resource' => 'dashboard', 'action' => 'view_financial', 'description' => 'Voir les rapports financiers'],
            ['name' => 'dashboard.export', 'resource' => 'dashboard', 'action' => 'export', 'description' => 'Exporter les données'],

            // Commandes
            ['name' => 'orders.view', 'resource' => 'orders', 'action' => 'view', 'description' => 'Voir les commandes'],
            ['name' => 'orders.create', 'resource' => 'orders', 'action' => 'create', 'description' => 'Créer des commandes'],
            ['name' => 'orders.update', 'resource' => 'orders', 'action' => 'update', 'description' => 'Modifier des commandes'],
            ['name' => 'orders.delete', 'resource' => 'orders', 'action' => 'delete', 'description' => 'Supprimer des commandes'],
            ['name' => 'orders.generate_auto', 'resource' => 'orders', 'action' => 'generate_auto', 'description' => 'Générer des commandes automatiques'],

            // Recettes
            ['name' => 'recipes.view', 'resource' => 'recipes', 'action' => 'view', 'description' => 'Voir les recettes'],
            ['name' => 'recipes.create', 'resource' => 'recipes', 'action' => 'create', 'description' => 'Créer des recettes'],
            ['name' => 'recipes.update', 'resource' => 'recipes', 'action' => 'update', 'description' => 'Modifier des recettes'],
            ['name' => 'recipes.prepare', 'resource' => 'recipes', 'action' => 'prepare', 'description' => 'Préparer des recettes'],

            // Mouvements de stock
            ['name' => 'stock_movements.view', 'resource' => 'stock_movements', 'action' => 'view', 'description' => 'Voir les mouvements de stock'],
            ['name' => 'stock_movements.create', 'resource' => 'stock_movements', 'action' => 'create', 'description' => 'Créer des mouvements de stock'],

            // Fournisseurs
            ['name' => 'suppliers.view', 'resource' => 'suppliers', 'action' => 'view', 'description' => 'Voir les fournisseurs'],
            ['name' => 'suppliers.create', 'resource' => 'suppliers', 'action' => 'create', 'description' => 'Créer des fournisseurs'],
            ['name' => 'suppliers.update', 'resource' => 'suppliers', 'action' => 'update', 'description' => 'Modifier des fournisseurs'],

            // Magasins et zones
            ['name' => 'stores.view', 'resource' => 'stores', 'action' => 'view', 'description' => 'Voir les magasins'],
            ['name' => 'stores.manage', 'resource' => 'stores', 'action' => 'manage', 'description' => 'Gérer les magasins'],

            // Utilisateurs et rôles
            ['name' => 'users.view', 'resource' => 'users', 'action' => 'view', 'description' => 'Voir les utilisateurs'],
            ['name' => 'users.create', 'resource' => 'users', 'action' => 'create', 'description' => 'Créer des utilisateurs'],
            ['name' => 'users.update', 'resource' => 'users', 'action' => 'update', 'description' => 'Modifier des utilisateurs'],
            ['name' => 'users.delete', 'resource' => 'users', 'action' => 'delete', 'description' => 'Supprimer des utilisateurs'],
            ['name' => 'users.manage_roles', 'resource' => 'users', 'action' => 'manage_roles', 'description' => 'Gérer les rôles des utilisateurs (Admin uniquement)'],

            // Liste d'achats
            ['name' => 'shopping_list.view', 'resource' => 'shopping_list', 'action' => 'view', 'description' => 'Voir la liste d\'achats'],
            ['name' => 'shopping_list.create', 'resource' => 'shopping_list', 'action' => 'create', 'description' => 'Ajouter à la liste d\'achats (tous les utilisateurs)'],
            ['name' => 'shopping_list.update', 'resource' => 'shopping_list', 'action' => 'update', 'description' => 'Modifier la liste d\'achats'],
            ['name' => 'shopping_list.delete', 'resource' => 'shopping_list', 'action' => 'delete', 'description' => 'Supprimer de la liste d\'achats'],
            ['name' => 'shopping_list.manage', 'resource' => 'shopping_list', 'action' => 'manage', 'description' => 'Gérer la liste d\'achats (marquer commandé/reçu)'],

            // Catégories produits (référentiel)
            ['name' => 'categories.view', 'resource' => 'categories', 'action' => 'view', 'description' => 'Voir les catégories produits'],
            ['name' => 'categories.create', 'resource' => 'categories', 'action' => 'create', 'description' => 'Créer des catégories'],
            ['name' => 'categories.update', 'resource' => 'categories', 'action' => 'update', 'description' => 'Modifier des catégories'],
            ['name' => 'categories.delete', 'resource' => 'categories', 'action' => 'delete', 'description' => 'Supprimer des catégories'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::firstOrCreate(
                ['name' => $permissionData['name']],
                $permissionData
            );
        }

        // Assigner les permissions aux rôles
        $allPermissions = Permission::all();
        
        // Pour le chef : toutes les permissions SAUF users.manage_roles
        $chefPermissions = $allPermissions->filter(function($permission) {
            return $permission->name !== 'users.manage_roles';
        })->pluck('name')->toArray();
        
        $rolePermissions = [
            // Admin - Toutes les permissions
            'admin' => $allPermissions->pluck('name')->toArray(),

            // Chef - Accès à TOUT sauf la gestion des rôles
            'chef' => $chefPermissions, // Inclut déjà toutes les permissions shopping_list

            // Cuisinier - Utilisation produits, préparation recettes
            'cook' => [
                'products.view', 'products.mark_expired',
                'alerts.view',
                'dashboard.view',
                'recipes.view', 'recipes.prepare',
                'stock_movements.view', 'stock_movements.create',
                'stores.view',
                'shopping_list.view', 'shopping_list.create', // Peut voir et ajouter à la liste
                'categories.view', // Listes déroulantes produits (pas de gestion du référentiel)
            ],

            // Magasinier - Réception, transferts, inventaire
            'storekeeper' => [
                'products.view', 'products.create', 'products.update', 'products.add_stock', 'products.mark_expired',
                'alerts.view',
                'dashboard.view',
                'orders.view', 'orders.create', 'orders.update', 'orders.generate_auto',
                'stock_movements.view', 'stock_movements.create',
                'suppliers.view',
                'stores.view',
                'shopping_list.view', 'shopping_list.create', 'shopping_list.update', 'shopping_list.manage', // Peut gérer la liste
                'categories.view', // Voir les catégories (formulaires produits) — CRUD réservé admin / chef / directeur
            ],

            // Comptable - Rapports financiers uniquement
            'accountant' => [
                'products.view',
                'alerts.view',
                'dashboard.view', 'dashboard.view_financial', 'dashboard.export',
                'orders.view',
                'stock_movements.view',
                'suppliers.view',
                'stores.view',
                'shopping_list.view', // Peut voir la liste
                'categories.view',
            ],

            // Boucher - Spécialisé dans la boucherie
            'butcher' => [
                'products.view', 'products.mark_expired',
                'alerts.view',
                'dashboard.view',
                'recipes.view',
                'stock_movements.view', 'stock_movements.create',
                'stores.view',
                'shopping_list.view', 'shopping_list.create', // Peut voir et ajouter à la liste
                'categories.view',
            ],

            // Serveur - Service en salle
            'server' => [
                'products.view',
                'alerts.view',
                'dashboard.view',
                'recipes.view', // Pour connaître les plats disponibles
                'stores.view',
                'shopping_list.view', 'shopping_list.create', // Peut voir et ajouter à la liste
                'categories.view',
            ],

            // Directeur - Accès complet (similaire à admin mais sans gestion des rôles)
            'director' => $allPermissions->filter(function($permission) {
                return $permission->name !== 'users.manage_roles';
            })->pluck('name')->toArray(),
        ];

        foreach ($rolePermissions as $role => $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                $permission = Permission::where('name', $permissionName)->first();
                if ($permission) {
                    RolePermission::firstOrCreate([
                        'role' => $role,
                        'permission_id' => $permission->id,
                    ]);
                }
            }
        }

        // Retirer le CRUD catégories au magasinier si déjà présent (référentiel = admin / chef / directeur)
        foreach (['categories.create', 'categories.update', 'categories.delete'] as $permName) {
            $perm = Permission::where('name', $permName)->first();
            if ($perm) {
                RolePermission::where('role', 'storekeeper')->where('permission_id', $perm->id)->delete();
            }
        }
    }
}
