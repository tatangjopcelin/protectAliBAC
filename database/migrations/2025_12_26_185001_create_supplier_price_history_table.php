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
        Schema::create('supplier_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('cascade'); // null = prix général du fournisseur
            $table->string('product_name')->nullable(); // Nom du produit au moment de l'enregistrement
            $table->decimal('price', 10, 2);
            $table->string('unit')->nullable();
            $table->date('effective_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('supplier_id');
            $table->index('product_id');
            $table->index('effective_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_price_history');
    }
};
