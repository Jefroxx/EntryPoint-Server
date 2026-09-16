<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Models\SystemNotification;
use Illuminate\Console\Command;

class NotifyDueSoonLoans extends Command
{
    protected $signature = 'loans:notify-due-soon';

    protected $description = 'Notify students whose active loans are due within the next 24 hours (once per loan).';

    private const WINDOW_HOURS = 24;

    public function handle(): int
    {
        $dueSoonLoans = Loan::where('status', 'Active')
            ->whereNull('dueSoonNotifiedAt')
            ->where('dueDate', '>', now())
            ->where('dueDate', '<=', now()->addHours(self::WINDOW_HOURS))
            ->with('copy.book')
            ->get();

        foreach ($dueSoonLoans as $loan) {
            SystemNotification::notify(
                $loan->studentID,
                "\"{$loan->copy->book->title}\" is due on {$loan->dueDate->format('M d, Y g:i A')} - return it soon to avoid a penalty.",
                'loan_due_soon'
            );

            $loan->update(['dueSoonNotifiedAt' => now()]);
        }

        $this->info("Sent due-soon reminders for {$dueSoonLoans->count()} loan(s).");

        return self::SUCCESS;
    }
}
