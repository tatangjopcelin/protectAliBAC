<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // Ajouter la colonne pour stocker la signature de départ (en base64)
            $table->text('clock_out_signature')->nullable()->after('clock_out')->comment('Signature de l\'employé lors du pointage de départ');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('clock_out_signature');
        });
    }
};
