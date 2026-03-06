<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * last_opened_at = ouverture de la page (badge à 0).
     * last_read_at = après envoi d'un message (séparateur "non lu" disparaît).
     */
    public function up(): void
    {
        Schema::table('internal_feed_reads', function (Blueprint $table) {
            $table->timestamp('last_opened_at')->nullable()->after('last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('internal_feed_reads', function (Blueprint $table) {
            $table->dropColumn('last_opened_at');
        });
    }
};
