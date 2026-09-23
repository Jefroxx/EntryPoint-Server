<?php

namespace App\Repositories\Contracts;

use App\Models\Penalty;
use App\Models\PenaltyRule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface PenaltyRepositoryInterface extends RepositoryInterface
{
    public function firstOrNewForLoan(int $loanID): Penalty;

    public function ruleForArea(string $area): ?PenaltyRule;

    public function unpaidCount(): int;

    public function forStudentWithBooks(int $studentID): Collection;

    public function unpaidTotalForStudent(int $studentID): float;

    public function paginate(?string $search, ?string $status, int $perPage): LengthAwarePaginator;

    /**
     * @return array{unpaidCount: int, unpaidTotal: float, paidCount: int}
     */
    public function stats(): array;

    public function fineRuleForArea(string $area): ?PenaltyRule;

    public function upsertFineRule(string $area, float $rate, string $rateUnit, int $gracePeriodDays): PenaltyRule;

    public function deleteFineRule(string $area): void;
}
