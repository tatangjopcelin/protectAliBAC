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
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Ex: 'products.create', 'dashboard.view'
            $table->string('resource'); // Ex: 'products', 'dashboard', 'orders'
            $table->string('action'); // Ex: 'create', 'update', 'delete', 'view'
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->index(['resource', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
