<?php

namespace App\Services;

use App\Models\Loan;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\BookRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(
        private BookRepositoryInterface $books,
        private StudentRepositoryInterface $students,
        private LoanRepositoryInterface $loans,
        private PenaltyRepositoryInterface $penalties,
        private BookCopyRepositoryInterface $bookCopies,
    ) {
    }

    public function summary(): array
    {
        return [
            'totalBooks'   => $this->books->totalCount(),
            'totalMembers' => $this->students->approvedCount(),
            'activeLoans'  => $this->loans->activeCount(),
            'pendingFines' => $this->penalties->unpaidCount(),
        ];
    }

    public function borrowingOverview(?string $month): array
    {
        $monthDate = $month ? Carbon::parse($month . '-01') : now();

        $start = $monthDate->copy()->startOfMonth();
        $end = $monthDate->copy()->endOfMonth();

        $weeks = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $weekEnd = $cursor->copy()->addDays(6)->min($end);
            $weeks[] = ['label' => $cursor->format('M j'), 'start' => $cursor->copy(), 'end' => $weekEnd->copy()];
            $cursor->addDays(7);
        }

        $borrowedByDay = $this->loans->countByDayBetween('checkoutDate', $start, $end);
        $returnedByDay = $this->loans->countByDayBetween('returnDate', $start, $end);

        $labels = [];
        $borrowed = [];
        $returned = [];

        foreach ($weeks as $week) {
            $labels[] = $week['label'];

            $days = collect(CarbonPeriod::create($week['start'], $week['end']))
                ->map(fn ($d) => $d->format('Y-m-d'));

            $borrowed[] = $days->sum(fn ($d) => $borrowedByDay->get($d, 0));
            $returned[] = $days->sum(fn ($d) => $returnedByDay->get($d, 0));
        }

        return compact('labels', 'borrowed', 'returned');
    }

    public function bookStatus(): array
    {
        $counts = $this->bookCopies->countsByStatus();

        $breakdown = [
            'available'   => $counts->get('available', 0),
            'borrowed'    => $counts->get('borrowed', 0),
            'maintenance' => $counts->get('damaged', 0),
            'inactive'    => $counts->get('lost', 0) + $counts->get('retired', 0),
        ];

        return ['total' => array_sum($breakdown), 'breakdown' => $breakdown];
    }

    public function recentLoans(int $limit = 5): Collection
    {
        return $this->loans->recentWithRelations($limit)->map(fn (Loan $loan) => [
            'studentName'  => $loan->student->user->fullName,
            'bookTitle'    => $loan->copy->book->title,
            'checkoutDate' => $loan->checkoutDate->toDateString(),
            'dueDate'      => $loan->dueDate->toDateString(),
            'status'       => $this->loanStatusLabel($loan),
        ]);
    }

    public function overdueLoans(int $limit = 5): Collection
    {
        return $this->loans->overdueWithRelations($limit)->map(fn (Loan $loan) => [
            'studentName' => $loan->student->user->fullName,
            'bookTitle'   => $loan->copy->book->title,
            'daysOverdue' => (int) $loan->dueDate->diffInDays(now()),
        ]);
    }

    private function loanStatusLabel(Loan $loan): string
    {
        if ($loan->status === 'Returned') {
            return 'Returned';
        }

        if ($loan->dueDate->isPast()) {
            return 'Due';
        }

        if ($loan->dueDate->lessThanOrEqualTo(now()->addDay())) {
            return 'Due Soon';
        }

        return 'Active';
    }
}
