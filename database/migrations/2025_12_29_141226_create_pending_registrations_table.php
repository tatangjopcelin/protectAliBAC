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
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password'); // Hashé
            $table->string('role')->default('cook');
            $table->unsignedBigInteger('zone_id')->nullable();
            $table->string('email_verification_code', 6);
            $table->timestamp('email_verification_code_expires_at');
            $table->timestamps();
            
            $table->index('email');
            $table->index('email_verification_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
