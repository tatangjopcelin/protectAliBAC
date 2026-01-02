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
        Schema::table('pending_registrations', function (Blueprint $table) {
            // Type d'inscription : 'create_store' ou 'join_store'
            $table->string('registration_type')->default('create_store')->after('email');
            
            // Infos pour création d'établissement
            $table->string('store_name')->nullable()->after('registration_type');
            $table->string('store_address')->nullable()->after('store_name');
            $table->string('store_phone')->nullable()->after('store_address');
            
            // Code pour rejoindre un établissement
            $table->string('establishment_code', 4)->nullable()->after('store_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'registration_type',
                'store_name',
                'store_address',
                'store_phone',
                'establishment_code'
            ]);
        });
    }
};
