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
        // Le champ email_verified_at existe déjà dans la table users
        // Cette migration est créée pour documentation
        // Aucune modification nécessaire
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Aucune modification nécessaire
    }
};
