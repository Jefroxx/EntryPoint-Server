<?php

namespace App\Repositories\Eloquent;

use App\Models\LoanPeriod;
use App\Repositories\Contracts\LoanPeriodRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentLoanPeriodRepository implements LoanPeriodRepositoryInterface
{
    public function all(): Collection
    {
        return LoanPeriod::all();
    }

    public function find(string $area): ?LoanPeriod
    {
        return LoanPeriod::find($area);
    }

    public function upsert(string $area, int $periodValue, string $periodUnit): LoanPeriod
    {
        $period = LoanPeriod::firstOrNew(['area' => $area], ['loanable' => true]);
        $period->periodValue = $periodValue;
        $period->periodUnit = $periodUnit;
        $period->save();

        return $period;
    }
}
