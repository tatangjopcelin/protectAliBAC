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
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Boucherie, Cuisine, Réserve sèche, Chambre froide, Congélateur
            $table->string('type'); // boucherie, cuisine, reserve_seche, chambre_froide, congelateur
            $table->text('description')->nullable();
            $table->string('shelf')->nullable(); // Étagère
            $table->string('bin')->nullable(); // Bac
            $table->decimal('temperature', 5, 2)->nullable(); // Température pour chambre froide/congélateur
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
