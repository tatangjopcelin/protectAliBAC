<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('supplier_response_seen_at')->nullable()->after('supplier_responded_at');
        });

        // Réponses déjà en base : considérées comme vues pour éviter un badge fictif massif
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement(
                'UPDATE orders SET supplier_response_seen_at = supplier_responded_at WHERE supplier_responded_at IS NOT NULL AND supplier_response_seen_at IS NULL'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'UPDATE orders SET supplier_response_seen_at = supplier_responded_at WHERE supplier_responded_at IS NOT NULL AND supplier_response_seen_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('supplier_response_seen_at');
        });
    }
};
