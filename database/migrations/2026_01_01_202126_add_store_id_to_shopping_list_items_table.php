<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('id')
                  ->constrained()->onDelete('cascade');
        });

        // Mettre à jour les enregistrements existants avec le store_id de l'utilisateur qui a ajouté l'item
        DB::statement("
            UPDATE shopping_list_items 
            INNER JOIN users ON users.id = shopping_list_items.added_by
            SET shopping_list_items.store_id = users.store_id
            WHERE shopping_list_items.store_id IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};

