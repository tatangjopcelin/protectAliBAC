<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Ancienne version rendait milieu nullable — révoquée (milieu obligatoire + défaut cuisine).
 * Conservé comme no-op pour ne pas casser l’historique des migrations déjà enregistrées.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
