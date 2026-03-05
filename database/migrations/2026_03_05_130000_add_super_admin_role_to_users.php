<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ajouter le rôle super_admin à l'enum existant
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN role ENUM(
                'admin',
                'chef',
                'cook',
                'storekeeper',
                'accountant',
                'butcher',
                'server',
                'director',
                'machine',
                'super_admin'
            ) NOT NULL DEFAULT 'cook'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revenir à l'enum sans super_admin (on laisse machine)
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN role ENUM(
                'admin',
                'chef',
                'cook',
                'storekeeper',
                'accountant',
                'butcher',
                'server',
                'director',
                'machine'
            ) NOT NULL DEFAULT 'cook'
        ");
    }
};

