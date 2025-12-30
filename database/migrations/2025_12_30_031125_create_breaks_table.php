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
        Schema::create('breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('start_break')->nullable(); // Début de pause
            $table->timestamp('end_break')->nullable(); // Fin de pause
            $table->integer('duration_minutes')->nullable(); // Durée en minutes
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['time_entry_id', 'user_id']);
            $table->index('start_break');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('breaks');
    }
};
