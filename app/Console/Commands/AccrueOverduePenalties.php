<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Models\Penalty;
use App\Models\SystemNotification;
use Illuminate\Console\Command;

class AccrueOverduePenalties extends Command
{
    protected $signature = 'penalties:accrue-overdue';

    protected $description = 'Recompute penalty amounts for all active overdue loans, and alert the student the first time each loan goes overdue.';

    public function handle(): int
    {
        $overdueLoans = Loan::where('status', 'Active')
            ->where('dueDate', '<', now())
            ->with('copy.book')
            ->get();

        foreach ($overdueLoans as $loan) {
            Penalty::accrueForLoan($loan);

            if (! $loan->overdueNotifiedAt) {
                SystemNotification::notify(
                    $loan->studentID,
                    "\"{$loan->copy->book->title}\" is now overdue. Please return it as soon as possible to stop further penalties from accruing.",
                    'loan_overdue'
                );

                $loan->update(['overdueNotifiedAt' => now()]);
            }
        }

        $this->info("Accrued penalties for {$overdueLoans->count()} overdue loan(s).");

        return self::SUCCESS;
    }
}
