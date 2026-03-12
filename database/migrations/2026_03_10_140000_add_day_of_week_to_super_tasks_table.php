<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jour à exécuter la super tâche (1=lundi … 7=dimanche). Affiché à l'employé.
     */
    public function up(): void
    {
        Schema::table('super_tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('day_of_week')->nullable()->after('week_start_date')->comment('1=lundi, 7=dimanche');
        });
    }

    public function down(): void
    {
        Schema::table('super_tasks', function (Blueprint $table) {
            $table->dropColumn('day_of_week');
        });
    }
};
