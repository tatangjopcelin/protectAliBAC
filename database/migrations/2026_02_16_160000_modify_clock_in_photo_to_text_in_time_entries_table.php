<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // Modifier la colonne clock_in_photo de string à text pour stocker les images base64
            $table->text('clock_in_photo')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // Revenir à string en cas de rollback
            $table->string('clock_in_photo')->nullable()->change();
        });
    }
};
