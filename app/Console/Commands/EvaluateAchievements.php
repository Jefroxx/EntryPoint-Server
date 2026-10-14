<?php

namespace App\Console\Commands;

use App\Services\AchievementService;
use Illuminate\Console\Command;

class EvaluateAchievements extends Command
{
    protected $signature = 'achievements:evaluate';

    protected $description = 'Re-check every approved student against every achievement and unlock the ones they have already earned.';

    public function handle(AchievementService $achievements): int
    {
        $unlocked = $achievements->evaluateForEveryone();

        $this->info("Unlocked {$unlocked} achievement(s).");

        return self::SUCCESS;
    }
}
