<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('web_push_subscriptions')) {
            Schema::create('web_push_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->text('endpoint');
                $table->string('endpoint_hash', 64);
                $table->text('public_key');
                $table->text('auth_token');
                $table->string('content_encoding')->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('web_push_subscriptions', 'endpoint_hash')) {
            Schema::table('web_push_subscriptions', function (Blueprint $table) {
                $table->string('endpoint_hash', 64)->nullable()->after('endpoint');
            });

            DB::table('web_push_subscriptions')
                ->whereNull('endpoint_hash')
                ->update(['endpoint_hash' => DB::raw('SHA2(endpoint, 256)')]);
        }

        // L'ajout d'index peut échouer si déjà présent: on ignore dans ce cas.
        try {
            Schema::table('web_push_subscriptions', function (Blueprint $table) {
                $table->unique(['user_id', 'endpoint_hash'], 'web_push_user_endpoint_hash_unique');
            });
        } catch (\Throwable $e) {
            // no-op
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};

