<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une absence par créneau planifié (schedule), pas une par jour.
     */
    public function up(): void
    {
        // MySQL utilise l’unique (user_id, date) comme index pour la FK sur user_id : il faut un index sur user_id avant de le supprimer.
        Schema::table('absences', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('absences', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'date']);
        });

        Schema::table('absences', function (Blueprint $table) {
            $table->unique('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->dropUnique(['schedule_id']);
        });

        Schema::table('absences', function (Blueprint $table) {
            $table->unique(['user_id', 'date']);
        });

        Schema::table('absences', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
