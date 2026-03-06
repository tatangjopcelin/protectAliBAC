<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * parent_id = message auquel on répond (réponse/citation).
     */
    public function up(): void
    {
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('receiver_id')->constrained('internal_messages')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });
    }
};
