<?php

namespace App\Console\Commands;

use App\Services\CirculationService;
use Illuminate\Console\Command;

class NotifyDueSoonLoans extends Command
{
    protected $signature = 'loans:notify-due-soon';

    protected $description = 'Notify students whose active loans are due within the next 24 hours (once per loan).';

    private const WINDOW_HOURS = 24;

    public function handle(CirculationService $circulation): int
    {
        $count = $circulation->notifyDueSoonLoans(self::WINDOW_HOURS);

        $this->info("Sent due-soon reminders for {$count} loan(s).");

        return self::SUCCESS;
    }
}
