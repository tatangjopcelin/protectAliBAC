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
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // 'recipe', 'order', 'consumption', 'waste_reduction'
            $table->string('title');
            $table->text('description');
            $table->json('data')->nullable(); // Données spécifiques selon le type
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('recipe_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('status', ['pending', 'accepted', 'rejected', 'dismissed'])->default('pending');
            $table->decimal('confidence_score', 3, 2)->nullable(); // Score de confiance 0-1
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
            
            $table->index('type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
