<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('stripe_price_id')->nullable()->comment('ID du prix Stripe (ex: price_xxx)');
            $table->unsignedInteger('amount_cents')->default(0)->comment('Prix en centimes pour affichage');
            $table->string('interval', 20)->default('month')->comment('month|year');
            $table->json('features')->nullable()->comment('Liste des avantages affichés');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
