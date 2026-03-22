<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milieu d’utilisation : boucherie, cuisine, bar (recherche / filtrage).
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('milieu', 32)->default('cuisine')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('milieu');
        });
    }
};
