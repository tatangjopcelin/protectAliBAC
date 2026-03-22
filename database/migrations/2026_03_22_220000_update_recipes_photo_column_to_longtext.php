<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Les photos sont envoyées en base64 (caméra / galerie) comme pour les produits.
     * VARCHAR(255) provoque SQLSTATE[22001] « Data too long ».
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->longText('photo')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('photo')->nullable()->change();
        });
    }
};
