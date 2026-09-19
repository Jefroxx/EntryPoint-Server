<?php

namespace App\Repositories\Contracts;

use App\Models\LoanPeriod;
use Illuminate\Database\Eloquent\Collection;

interface LoanPeriodRepositoryInterface
{
    public function all(): Collection;

    public function find(string $area): ?LoanPeriod;

    public function upsert(string $area, int $periodValue, string $periodUnit): LoanPeriod;
}
