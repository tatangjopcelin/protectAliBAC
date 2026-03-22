<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rétablit milieu NOT NULL avec défaut « cuisine » (bases passées en nullable par l’ancienne 2026_03_24).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('recipes', 'milieu')) {
            return;
        }
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        DB::table('recipes')->whereNull('milieu')->update(['milieu' => 'cuisine']);

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `recipes` MODIFY `milieu` VARCHAR(32) NOT NULL DEFAULT \'cuisine\'');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recipes ALTER COLUMN milieu SET DEFAULT \'cuisine\'');
            DB::statement('ALTER TABLE recipes ALTER COLUMN milieu SET NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('recipes', 'milieu')) {
            return;
        }
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `recipes` MODIFY `milieu` VARCHAR(32) NULL DEFAULT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE recipes ALTER COLUMN milieu DROP NOT NULL');
            DB::statement('ALTER TABLE recipes ALTER COLUMN milieu DROP DEFAULT');
        }
    }
};
