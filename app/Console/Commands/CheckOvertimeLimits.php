<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OvertimeCheckService;

class CheckOvertimeLimits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'time-entries:check-overtime';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie les pointages en cours et pointe automatiquement la sortie si la limite d\'heures supplémentaires est atteinte';

    /**
     * Execute the console command.
     */
    public function handle(OvertimeCheckService $overtimeCheck)
    {
        $this->info('Vérification des limites d\'heures supplémentaires...');

        $autoClockedCount = $overtimeCheck->runCheck();

        $this->info("Vérification terminée. {$autoClockedCount} pointage(s) automatique(s).");

        return Command::SUCCESS;
    }
}

