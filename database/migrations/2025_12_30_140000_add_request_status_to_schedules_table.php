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
        // Modifier l'enum pour ajouter 'request'
        DB::statement("ALTER TABLE schedules MODIFY COLUMN status ENUM('planned', 'confirmed', 'cancelled', 'request') DEFAULT 'planned'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Retirer 'request' de l'enum
        DB::statement("ALTER TABLE schedules MODIFY COLUMN status ENUM('planned', 'confirmed', 'cancelled') DEFAULT 'planned'");
    }
};



