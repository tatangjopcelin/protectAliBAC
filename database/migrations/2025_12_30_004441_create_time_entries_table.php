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
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('schedule_id')->nullable()->constrained()->onDelete('set null');
            $table->date('date');
            $table->timestamp('clock_in')->nullable(); // Heure d'arrivée
            $table->timestamp('clock_out')->nullable(); // Heure de départ
            $table->decimal('hours_worked', 5, 2)->nullable(); // Heures travaillées calculées
            $table->decimal('break_duration', 4, 2)->nullable(); // Durée de pause en heures
            $table->enum('status', ['present', 'absent', 'late', 'early_leave'])->default('present');
            $table->string('location')->nullable(); // Localisation GPS si disponible
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
