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
