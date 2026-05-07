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
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'supplier_token')) {
                $table->string('supplier_token', 128)->nullable()->unique()->after('notes');
            }
            if (!Schema::hasColumn('orders', 'supplier_token_expires_at')) {
                $table->timestamp('supplier_token_expires_at')->nullable()->after('supplier_token');
            }
            if (!Schema::hasColumn('orders', 'supplier_responded_at')) {
                $table->timestamp('supplier_responded_at')->nullable()->after('supplier_token_expires_at');
            }
            if (!Schema::hasColumn('orders', 'supplier_response_note')) {
                $table->text('supplier_response_note')->nullable()->after('supplier_responded_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['supplier_response_note', 'supplier_responded_at', 'supplier_token_expires_at', 'supplier_token'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
