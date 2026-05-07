<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->longText('delivery_photo')->nullable()->after('supplier_confirmation_note');
            $table->longText('supplier_delivery_signature')->nullable()->after('delivery_photo');
            $table->longText('establishment_delivery_signature')->nullable()->after('supplier_delivery_signature');
            $table->foreignId('delivery_received_by_user_id')->nullable()->after('establishment_delivery_signature')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_received_by_user_id']);
            $table->dropColumn([
                'delivery_photo',
                'supplier_delivery_signature',
                'establishment_delivery_signature',
                'delivery_received_by_user_id',
            ]);
        });
    }
};
