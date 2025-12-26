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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->constrained()->onDelete('restrict');
            $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('zone_id')->constrained()->onDelete('restrict');
            $table->decimal('quantity', 10, 3)->default(0);
            $table->string('unit'); // kg, pièce, litre, etc.
            $table->decimal('min_quantity', 10, 3)->default(0); // Stock minimum pour alertes
            $table->date('reception_date');
            $table->date('expiration_date');
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->string('photo')->nullable(); // Chemin vers la photo
            $table->string('barcode')->nullable(); // Code-barres
            $table->text('notes')->nullable();
            $table->enum('status', ['ok', 'warning', 'expired'])->default('ok'); // 🟢 🟠 🔴
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('expiration_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
