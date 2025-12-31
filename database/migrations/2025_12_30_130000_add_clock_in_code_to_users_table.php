<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('clock_in_code', 3)->nullable()->after('max_overtime_hours')
                ->comment('Code de vérification pour le pointage (3 chiffres)');
            $table->timestamp('clock_in_code_expires_at')->nullable()->after('clock_in_code')
                ->comment('Date d\'expiration du code de pointage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['clock_in_code', 'clock_in_code_expires_at']);
        });
    }
};



