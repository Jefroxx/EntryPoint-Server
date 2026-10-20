<?php

namespace App\Repositories\Eloquent;

use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class EloquentPenaltyRepository extends BaseRepository implements PenaltyRepositoryInterface
{
    public function __construct(Penalty $model)
    {
        parent::__construct($model);
    }

    public function firstOrNewForLoan(int $loanID): Penalty
    {
        return Penalty::firstOrNew(['loanID' => $loanID]);
    }

    public function ruleForArea(string $area): ?PenaltyRule
    {
        return PenaltyType::where('category', $area)->first()?->rules()->first();
    }

    public function unpaidCount(): int
    {
        return Penalty::where('paymentStatus', 'Unpaid')->count();
    }

    public function forStudentWithBooks(int $studentID): Collection
    {
        return Penalty::whereHas('loan', fn ($query) => $query->where('studentID', $studentID))
            ->with([
                'loan.copy.book' => fn ($query) => $query->withTrashed(),
                'loan.copy.book.authors',
                'loan.copy.book.subject',
            ])
            ->orderByDesc('computedAt')
            ->get();
    }

    public function unpaidTotalForStudent(int $studentID): float
    {
        return (float) Penalty::whereHas('loan', fn ($query) => $query->where('studentID', $studentID))
            ->where('paymentStatus', 'Unpaid')
            ->sum('amount');
    }

    public function paginate(?string $search, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Penalty::with(['loan.student.user', 'loan.copy.book', 'penaltyType'])
            // Grouped, so the status filter below applies to both halves of the search.
            ->when($search, fn ($query, $term) => $query->where(fn ($sq) => $sq->whereHas(
                'loan.student.user',
                fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")->orWhere('lastName', 'like', "%{$term}%")
            )->orWhereHas(
                'loan.copy.book',
                fn ($bq) => $bq->where('title', 'like', "%{$term}%")
            )))
            ->when($status === 'longUnpaid', fn ($query) => $this->longUnpaid($query))
            ->when($status && $status !== 'longUnpaid', fn ($query) => $query->where('paymentStatus', $status))
            ->orderByDesc('computedAt')
            ->paginate($perPage);
    }

    public function stats(): array
    {
        return [
            'unpaidCount' => Penalty::where('paymentStatus', 'Unpaid')->count(),
            'unpaidTotal' => (float) Penalty::where('paymentStatus', 'Unpaid')->sum('amount'),
            'paidCount'   => Penalty::where('paymentStatus', 'Paid')->count(),
            'longUnpaidCount' => $this->longUnpaid(Penalty::query())->count(),
            'longUnpaidDays'  => Penalty::LONG_UNPAID_DAYS,
        ];
    }

    /** Unpaid fines whose book came back more than Penalty::LONG_UNPAID_DAYS days ago. */
    private function longUnpaid(Builder $query): Builder
    {
        return $query->where('paymentStatus', 'Unpaid')->whereHas('loan', fn ($lq) => $lq
            ->where('status', 'Returned')
            ->where('returnDate', '<=', now()->subDays(Penalty::LONG_UNPAID_DAYS)));
    }

    public function fineRuleForArea(string $area): ?PenaltyRule
    {
        return PenaltyType::where('category', $area)->first()?->rules()->first();
    }

    public function upsertFineRule(string $area, float $rate, string $rateUnit, int $gracePeriodDays): PenaltyRule
    {
        $type = PenaltyType::firstOrCreate(
            ['category' => $area],
            ['uuid' => Str::uuid()]
        );

        $rule = $type->rules()->first() ?? new PenaltyRule(['uuid' => Str::uuid(), 'penaltyTypeID' => $type->penaltyTypeID]);
        $rule->rate = $rate;
        $rule->rateUnit = $rateUnit;
        $rule->gracePeriodDays = $gracePeriodDays;
        $rule->save();

        return $rule;
    }

    public function deleteFineRule(string $area): void
    {
        $type = PenaltyType::where('category', $area)->first();

        $type?->rules()->delete();
    }
}
