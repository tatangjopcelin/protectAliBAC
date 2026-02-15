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
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director', 'machine') NOT NULL DEFAULT 'cook'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'chef', 'cook', 'storekeeper', 'accountant', 'butcher', 'server', 'director') NOT NULL DEFAULT 'cook'");
    }
};
