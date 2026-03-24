<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\RecipeIngredient;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->string('product_name_snapshot', 255)->nullable()->after('product_id');
        });

        foreach (RecipeIngredient::query()->with('product')->whereNull('product_name_snapshot')->cursor() as $row) {
            if ($row->product) {
                $row->product_name_snapshot = $row->product->name;
                $row->saveQuietly();
            }
        }

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE recipe_ingredients MODIFY product_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recipe_ingredients ALTER COLUMN product_id DROP NOT NULL');
        } else {
            Schema::table('recipe_ingredients', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->nullable()->change();
            });
        }

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        DB::table('recipe_ingredients')->whereNull('product_id')->delete();

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE recipe_ingredients MODIFY product_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recipe_ingredients ALTER COLUMN product_id SET NOT NULL');
        } else {
            Schema::table('recipe_ingredients', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->nullable(false)->change();
            });
        }

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->dropColumn('product_name_snapshot');
        });
    }
};
