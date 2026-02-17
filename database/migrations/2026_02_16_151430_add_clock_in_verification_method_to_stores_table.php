<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->enum('clock_in_verification_method', ['code', 'photo'])->default('code')->after('trial_ends_at')->comment('Méthode de vérification pour le pointage: code par email ou photo');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('clock_in_verification_method');
        });
    }
};
