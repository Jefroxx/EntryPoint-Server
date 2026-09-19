<?php

namespace App\Repositories\Eloquent;

use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    public function paginate(?string $search, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Penalty::with(['loan.student.user', 'loan.copy.book', 'penaltyType'])
            ->when($search, fn ($query, $term) => $query->whereHas(
                'loan.student.user',
                fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")->orWhere('lastName', 'like', "%{$term}%")
            )->orWhereHas(
                'loan.copy.book',
                fn ($bq) => $bq->where('title', 'like', "%{$term}%")
            ))
            ->when($status, fn ($query, $value) => $query->where('paymentStatus', $value))
            ->orderByDesc('computedAt')
            ->paginate($perPage);
    }

    public function stats(): array
    {
        return [
            'unpaidCount' => Penalty::where('paymentStatus', 'Unpaid')->count(),
            'unpaidTotal' => (float) Penalty::where('paymentStatus', 'Unpaid')->sum('amount'),
            'paidCount'   => Penalty::where('paymentStatus', 'Paid')->count(),
        ];
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
