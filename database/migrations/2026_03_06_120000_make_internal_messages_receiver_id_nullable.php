<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages avec receiver_id null = visible par tout l'établissement (fil d'actualité).
     */
    public function up(): void
    {
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->dropForeign(['receiver_id']);
        });
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('receiver_id')->nullable()->change();
        });
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->dropForeign(['receiver_id']);
        });
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('receiver_id')->nullable(false)->change();
        });
        Schema::table('internal_messages', function (Blueprint $table) {
            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
