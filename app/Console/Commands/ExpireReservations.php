<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire accepted reservations that were not collected within the hold period (Settings), freeing their copies.';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->expireStale();

        $this->info("Expired {$count} reservation(s).");

        return self::SUCCESS;
    }
}
