<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly, not daily — Reserved items bill per hour, so a daily run
// would under-charge them for most of the day they're overdue.
Schedule::command('penalties:accrue-overdue')->hourly();

// Hourly to match — Reserved loans are due overnight (~1 day), so a
// daily run could miss or badly delay their 24-hour due-soon window.
Schedule::command('loans:notify-due-soon')->hourly();
