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
        // Marquer tous les comptes existants (créés avant l'implémentation de la vérification) comme vérifiés
        // Cela permet aux utilisateurs existants de continuer à se connecter
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update([
                'email_verified_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Ne rien faire - on ne peut pas vraiment "dé-vérifier" les comptes
        // Cette migration est irréversible par design
    }
};
