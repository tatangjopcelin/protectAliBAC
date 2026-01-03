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
        Schema::create('super_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['friteuse', 'chambre_froide']);
            $table->foreignId('assigned_to')->constrained('users')->onDelete('cascade');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null');
            $table->date('week_start_date'); // Date de début de la semaine d'assignation
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();
            
            // Champs spécifiques pour la friteuse
            $table->boolean('oil_changed')->default(false)->nullable();
            $table->boolean('cleaned')->default(false)->nullable();
            $table->text('friteuse_notes')->nullable();
            
            // Champs spécifiques pour la chambre froide
            $table->boolean('organized')->default(false)->nullable();
            $table->text('chambre_froide_notes')->nullable();
            
            $table->text('general_notes')->nullable();
            $table->timestamps();
            
            $table->index(['store_id', 'type', 'week_start_date']);
            $table->index(['assigned_to', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('super_tasks');
    }
};
