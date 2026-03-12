<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ajoute la valeur 'absent' à l'enum status de la table tasks
     * (tâche non faite à temps, mise à jour par tasks:notify-overdue).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'cancelled', 'absent') DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remettre les tâches "absent" en "pending" avant de retirer la valeur
        DB::table('tasks')->where('status', 'absent')->update(['status' => 'pending']);
        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending'");
    }
};
