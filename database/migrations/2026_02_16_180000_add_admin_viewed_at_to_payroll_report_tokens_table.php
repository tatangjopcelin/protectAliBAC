<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_report_tokens', function (Blueprint $table) {
            // Ajouter la colonne pour savoir si l'admin a vu les réponses (confirmé/rejeté)
            $table->timestamp('admin_viewed_at')->nullable()->after('responded_at')->comment('Date à laquelle l\'admin a vu les réponses confirmées/rejetées');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_report_tokens', function (Blueprint $table) {
            $table->dropColumn('admin_viewed_at');
        });
    }
};
