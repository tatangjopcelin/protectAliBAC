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
        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nom du produit
            $table->decimal('quantity', 10, 3)->default(1); // Quantité à acheter
            $table->string('unit')->default('unité'); // Unité (kg, litre, pièce, etc.)
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null'); // Catégorie optionnelle
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null'); // Produit existant (optionnel)
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium'); // Priorité
            $table->enum('status', ['pending', 'ordered', 'received', 'cancelled'])->default('pending'); // Statut
            $table->foreignId('added_by')->constrained('users')->onDelete('cascade'); // Utilisateur qui a ajouté
            $table->foreignId('ordered_by')->nullable()->constrained('users')->onDelete('set null'); // Utilisateur qui a commandé
            $table->text('notes')->nullable(); // Notes supplémentaires
            $table->timestamp('ordered_at')->nullable(); // Date de commande
            $table->timestamp('received_at')->nullable(); // Date de réception
            $table->timestamps();
            
            $table->index('status');
            $table->index('priority');
            $table->index('added_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
    }
};
