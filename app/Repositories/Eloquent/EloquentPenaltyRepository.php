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
        // The late-return fine only: a lost/damaged penalty on the same loan is a different charge.
        return Penalty::where('loanID', $loanID)
            ->whereDoesntHave('penaltyType', fn ($query) => $query->whereIn('category', Penalty::COPY_CATEGORIES))
            ->first() ?? new Penalty(['loanID' => $loanID]);
    }

    public function openForCopies(array $copyIDs): Collection
    {
        return Penalty::where('paymentStatus', 'Unpaid')
            ->whereHas('penaltyType', fn ($query) => $query->whereIn('category', Penalty::COPY_CATEGORIES))
            ->whereIn('copyID', $copyIDs)
            ->with(Penalty::listingRelations())
            ->get();
    }

    public function openForCopy(int $copyID): ?Penalty
    {
        return $this->openForCopies([$copyID])->first();
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
        return Penalty::where('studentID', $studentID)
            ->with([
                'penaltyType',
                'loan',
                'copy.book' => fn ($query) => $query->withTrashed(),
                'copy.book.authors',
                'copy.book.subject',
            ])
            ->orderByDesc('computedAt')
            ->get();
    }

    public function unpaidTotalForStudent(int $studentID): float
    {
        return (float) Penalty::where('studentID', $studentID)
            ->where('paymentStatus', 'Unpaid')
            ->sum('amount');
    }

    public function paginate(?string $search, ?string $status, int $perPage, ?string $kind = null): LengthAwarePaginator
    {
        return $this->ofKind(Penalty::with(Penalty::listingRelations()), $kind)
            // Grouped, so the status filter below applies to both halves of the search.
            ->when($search, fn ($query, $term) => $query->where(fn ($sq) => $sq->whereHas(
                'student.user',
                fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")->orWhere('lastName', 'like', "%{$term}%")
            )->orWhereHas(
                'copy.book',
                fn ($bq) => $bq->where('title', 'like', "%{$term}%")
            )))
            ->when($status === 'longUnpaid', fn ($query) => $this->longUnpaid($query))
            ->when($status && $status !== 'longUnpaid', fn ($query) => $query->where('paymentStatus', $status))
            ->orderByDesc('computedAt')
            ->paginate($perPage);
    }

    public function stats(?string $kind = null): array
    {
        $of = fn () => $this->ofKind(Penalty::query(), $kind);

        return [
            'unpaidCount' => $of()->where('paymentStatus', 'Unpaid')->count(),
            'unpaidTotal' => (float) $of()->where('paymentStatus', 'Unpaid')->sum('amount'),
            'paidCount'   => $of()->where('paymentStatus', 'Paid')->count(),
            'replacedCount' => $of()->where('paymentStatus', 'Replaced')->count(),
            'longUnpaidCount' => $this->longUnpaid($of())->count(),
            'longUnpaidDays'  => Penalty::LONG_UNPAID_DAYS,
        ];
    }

    /** `'copy'`: only penalties for a lost or damaged book; `'late'`: only late fees; null: both. */
    private function ofKind(Builder $query, ?string $kind): Builder
    {
        $copyTypes = fn ($tq) => $tq->whereIn('category', Penalty::COPY_CATEGORIES);

        return match ($kind) {
            'copy'  => $query->whereHas('penaltyType', $copyTypes),
            'late'  => $query->whereDoesntHave('penaltyType', $copyTypes),
            default => $query,
        };
    }

    /**
     * Unpaid fines that have waited more than Penalty::LONG_UNPAID_DAYS days: a late fee from the day its book came
     * back, a lost/damaged penalty from the day it was issued.
     */
    private function longUnpaid(Builder $query): Builder
    {
        $cutoff = now()->subDays(Penalty::LONG_UNPAID_DAYS);
        $copyTypes = fn ($tq) => $tq->whereIn('category', Penalty::COPY_CATEGORIES);

        return $query->where('paymentStatus', 'Unpaid')->where(fn ($either) => $either
            ->where(fn ($late) => $late
                ->whereDoesntHave('penaltyType', $copyTypes)
                ->whereHas('loan', fn ($lq) => $lq->where('status', 'Returned')->where('returnDate', '<=', $cutoff)))
            ->orWhere(fn ($copy) => $copy
                ->whereHas('penaltyType', $copyTypes)
                ->where('computedAt', '<=', $cutoff)));
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
