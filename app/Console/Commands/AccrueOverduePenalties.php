<?php

namespace App\Console\Commands;

use App\Services\CirculationService;
use Illuminate\Console\Command;

class AccrueOverduePenalties extends Command
{
    protected $signature = 'penalties:accrue-overdue';

    protected $description = 'Recompute penalty amounts for all active overdue loans, and alert the student the first time each loan goes overdue.';

    public function handle(CirculationService $circulation): int
    {
        $count = $circulation->accrueOverduePenalties();

        $this->info("Accrued penalties for {$count} overdue loan(s).");

        return self::SUCCESS;
    }
}
