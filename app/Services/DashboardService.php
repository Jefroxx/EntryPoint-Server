<?php

namespace App\Services;

use App\Models\Loan;
use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\BookRepositoryInterface;
use App\Repositories\Contracts\BookSuggestionRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\PointRedemptionRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\SelfReturnReportRepositoryInterface;
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
        private AttendanceLogRepositoryInterface $attendanceLogs,
        private BookSuggestionRepositoryInterface $suggestions,
        private ReservationRepositoryInterface $reservations,
        private SelfReturnReportRepositoryInterface $selfReturns,
        private PointRedemptionRepositoryInterface $redemptions,
    ) {
    }

    /**
     * What the front desk needs at a glance: today's traffic, and every queue waiting on a librarian.
     * One request so the dashboard's top half fills in at once instead of in six pieces.
     */
    public function today(): array
    {
        $loanStats = $this->loans->stats();
        $fines = $this->penalties->stats();

        return [
            'inLibrary'   => $this->attendanceLogs->currentlyInLibraryCount(),
            'visitsToday' => $this->attendanceLogs->totalVisitsToday(),
            ...$this->loans->todayCounts(),
            'overdue'     => $loanStats['overdue'],
            'attention'   => [
                'registrations' => $this->students->readyForReviewCount(),
                'bookRequests'  => $this->suggestions->countWhere(['status' => 'Pending']),
                'reservations'  => $this->reservations->countWhere(['status' => 'Waiting']),
                'selfReturns'   => $this->selfReturns->countWhere(['verificationStatus' => 'Pending']),
                'redemptions'   => $this->redemptions->countWhere(['fulfillmentStatus' => 'Pending']),
                'unpaidFines'   => $fines['unpaidCount'],
                'unpaidTotal'   => $fines['unpaidTotal'],
            ],
        ];
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
        // One row per visit; grouped by week below so "different students" counts each person once a week.
        $visitDays = $this->attendanceLogs->visitDaysBetween($start->copy()->startOfDay(), $end->copy()->endOfDay());

        $labels = [];
        $borrowed = [];
        $returned = [];
        $visits = [];
        $visitors = [];

        foreach ($weeks as $week) {
            $labels[] = $week['label'];

            $days = collect(CarbonPeriod::create($week['start'], $week['end']))
                ->map(fn ($d) => $d->format('Y-m-d'));

            $borrowed[] = $days->sum(fn ($d) => $borrowedByDay->get($d, 0));
            $returned[] = $days->sum(fn ($d) => $returnedByDay->get($d, 0));

            $weekVisits = $visitDays->whereIn('day', $days->all());
            $visits[] = $weekVisits->count();
            $visitors[] = $weekVisits->pluck('studentID')->unique()->count();
        }

        // Different students across the whole month; not the sum of the weeks (a regular would count 4 times).
        $visitorsTotal = $visitDays->pluck('studentID')->unique()->count();

        return compact('labels', 'borrowed', 'returned', 'visits', 'visitors', 'visitorsTotal');
    }

    /**
     * Students by academic program: every approved member, or only those who visited in the month.
     * The five largest programs keep their name; the rest fold into "Other" so the pie stays readable.
     *
     * @return array{total: int, slices: array<int, array{label: string, count: int}>}
     */
    public function demographics(string $scope, ?string $month): array
    {
        if ($scope === 'visitors') {
            $monthDate = $month ? Carbon::parse($month . '-01') : now();
            $counts = $this->attendanceLogs->visitorsByProgramBetween(
                $monthDate->copy()->startOfMonth(),
                $monthDate->copy()->endOfMonth(),
            );
        } else {
            $counts = $this->students->approvedCountByProgram();
        }

        $sorted = $counts
            ->mapWithKeys(fn ($total, $program) => [($program !== '' && $program !== null) ? $program : 'Not set' => (int) $total])
            ->sortDesc();

        $slices = $sorted->take(5)->map(fn ($count, $label) => ['label' => (string) $label, 'count' => $count])->values();
        $rest = $sorted->slice(5)->sum();
        if ($rest > 0) {
            $slices->push(['label' => 'Other', 'count' => $rest]);
        }

        return ['total' => $sorted->sum(), 'slices' => $slices->all()];
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
