<?php

namespace App\Console\Commands;

use App\Models\Simulation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('simulations:purge-expired')]
#[Description('Remove simulations and related data older than 180 days')]
class PurgeExpiredSimulations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = Simulation::query()->where('created_at', '<=', now()->subDays(180))->delete();
        $this->info("Simulações expiradas removidas: {$deleted}.");

        return self::SUCCESS;
    }
}
