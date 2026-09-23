<?php

namespace App\Console\Commands;

use App\Services\AttendanceService;
use Illuminate\Console\Command;

class CloseStaleAttendance extends Command
{
    protected $signature = 'attendance:close-stale';

    protected $description = 'Close library visits that were never scanned out on an earlier day.';

    public function handle(AttendanceService $attendance): int
    {
        $count = $attendance->closeStaleVisits();

        $this->info("Closed {$count} stale visit(s).");

        return self::SUCCESS;
    }
}
