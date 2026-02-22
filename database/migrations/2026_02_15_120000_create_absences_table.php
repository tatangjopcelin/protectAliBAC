<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Absence = programmé mais n'a pas pointé ce jour-là (détecté le lendemain).
     */
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->foreignId('schedule_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('store_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['store_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
