<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modifier l'enum pour ajouter les nouveaux rôles
        // MySQL ne supporte pas la modification directe d'enum, on doit recréer la colonne
        DB::statement("ALTER TABLE role_permissions MODIFY COLUMN role ENUM('admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revenir à l'ancien enum
        DB::statement("ALTER TABLE role_permissions MODIFY COLUMN role ENUM('admin', 'chef', 'cook', 'storekeeper', 'accountant') NOT NULL");
    }
};
