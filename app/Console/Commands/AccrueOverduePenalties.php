<?php

namespace App\Console\Commands;

use App\Models\Loan;
use App\Models\Penalty;
use Illuminate\Console\Command;

class AccrueOverduePenalties extends Command
{
    protected $signature = 'penalties:accrue-overdue';

    protected $description = 'Recompute penalty amounts for all active overdue loans.';

    public function handle(): int
    {
        $overdueLoans = Loan::where('status', 'Active')
            ->where('dueDate', '<', now())
            ->get();

        foreach ($overdueLoans as $loan) {
            Penalty::accrueForLoan($loan);
        }

        $this->info("Accrued penalties for {$overdueLoans->count()} overdue loan(s).");

        return self::SUCCESS;
    }
}
