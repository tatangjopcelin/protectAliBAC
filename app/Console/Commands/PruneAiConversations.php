<?php

namespace App\Console\Commands;

use App\Models\AiConversation;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneAiConversations extends Command
{
    protected $signature = 'ai:prune-conversations';

    protected $description = 'Supprime les conversations IA de plus de 3 jours';

    public function handle(): int
    {
        $threshold = Carbon::now()->subDays(3);

        $deleted = AiConversation::where('created_at', '<', $threshold)->delete();

        $this->info("🧹 Conversations IA supprimées: {$deleted} (avant {$threshold->toDateTimeString()})");

        return Command::SUCCESS;
    }
}

