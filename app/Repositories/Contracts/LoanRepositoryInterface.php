<?php

namespace App\Repositories\Contracts;

use App\Models\Loan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

interface LoanRepositoryInterface extends RepositoryInterface
{
    public function loadForResponse(Loan $loan): Loan;

    public function paginate(?string $search, ?string $status, int $perPage): LengthAwarePaginator;

    /**
     * @return array{active: int, dueSoon: int, overdue: int}
     */
    public function stats(): array;

    public function overdueActive(): Collection;

    public function dueSoonNotUnnotified(int $windowHours): Collection;

    public function activeCount(): int;

    /**
     * Counts non-null $dateColumn values between $start and $end, grouped
     * by calendar day, e.g. for a checkout/return activity chart.
     */
    public function countByDayBetween(string $dateColumn, \DateTimeInterface $start, \DateTimeInterface $end): BaseCollection;

    public function recentWithRelations(int $limit): Collection;

    public function overdueWithRelations(int $limit): Collection;

    public function countCheckoutsBetween(\DateTimeInterface $start, \DateTimeInterface $end): int;

    /**
     * Percentage of loans returned on or before their dueDate within the
     * window. Null when nothing was returned in the window at all.
     */
    public function onTimeReturnRate(\DateTimeInterface $start, \DateTimeInterface $end): ?float;

    /**
     * Checkout/return counts grouped by calendar month ("Y-m") within the
     * window, for a multi-month activity chart.
     *
     * @return array{checkedOut: BaseCollection, returned: BaseCollection}
     */
    public function countsByMonthBetween(\DateTimeInterface $start, \DateTimeInterface $end): array;

    /**
     * @return BaseCollection<int,object{title:string,total:int}>
     */
    public function topBooksBetween(\DateTimeInterface $start, \DateTimeInterface $end, int $limit): BaseCollection;
}
