<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accès libre accordé par le super admin (établissement qui ne paie plus).
     * Si non null, l'établissement peut utiliser l'app comme en essai gratuit.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('free_access_granted_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('free_access_granted_at');
        });
    }
};
