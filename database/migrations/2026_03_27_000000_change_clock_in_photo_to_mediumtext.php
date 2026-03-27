<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEXT MySQL (~64 Ko) est trop petit pour une data URL base64 (~300–500 Ko).
 * MEDIUMTEXT permet jusqu’à ~16 Mo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE time_entries MODIFY clock_in_photo MEDIUMTEXT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE time_entries MODIFY clock_in_photo TEXT NULL');
        }
    }
};
