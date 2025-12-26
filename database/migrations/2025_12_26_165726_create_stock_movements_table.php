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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('type', ['entry', 'exit', 'transfer', 'used', 'wasted', 'transformed']);
            $table->decimal('quantity', 10, 3);
            $table->foreignId('from_zone_id')->nullable()->constrained('zones')->onDelete('set null');
            $table->foreignId('to_zone_id')->nullable()->constrained('zones')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recipe_id')->nullable(); // Si utilisé dans une recette (foreign key ajoutée après création de recipes)
            $table->timestamps();
            
            $table->index('type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
