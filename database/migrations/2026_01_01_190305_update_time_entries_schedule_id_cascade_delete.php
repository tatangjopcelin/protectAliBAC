<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Change la suppression de cascade de 'set null' à 'cascade' pour schedule_id
     */
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // Supprimer l'ancienne contrainte de clé étrangère
            $table->dropForeign(['schedule_id']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            // Recréer la contrainte avec cascade delete
            $table->foreign('schedule_id')
                  ->references('id')
                  ->on('schedules')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // Supprimer la contrainte cascade
            $table->dropForeign(['schedule_id']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            // Recréer la contrainte avec set null (comme avant)
            $table->foreign('schedule_id')
                  ->references('id')
                  ->on('schedules')
                  ->onDelete('set null');
        });
    }
};
