<?php

namespace App\Services;

use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\BookSubjectRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use Illuminate\Support\Carbon;

class ReportService
{
    private const WEEKDAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    public function __construct(
        private LoanRepositoryInterface $loans,
        private AttendanceLogRepositoryInterface $attendanceLogs,
        private PenaltyRepositoryInterface $penalties,
        private BookSubjectRepositoryInterface $subjects,
        private BookCopyRepositoryInterface $bookCopies,
    ) {
    }

    public function overview(int $months): array
    {
        $end = now()->endOfDay();
        $start = now()->subMonths($months - 1)->startOfMonth();
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $avgVisitMinutes = $this->attendanceLogs->averageMinutesBetween($start, $end);

        return [
            'kpis' => [
                'loansThisMonth'   => $this->loans->countCheckoutsBetween($monthStart, $monthEnd),
                'onTimeRate'       => $this->loans->onTimeReturnRate($start, $end),
                'avgVisitMinutes'  => $avgVisitMinutes > 0 ? $avgVisitMinutes : null,
                'finesOutstanding' => $this->penalties->stats()['unpaidTotal'],
            ],
            'loansPerMonth'    => $this->loansPerMonth($start, $end),
            'visitsByWeekday'  => $this->visitsByWeekday($start, $end),
            'topBooks'         => $this->topBooks($start, $end),
            'booksByCategory'  => $this->booksByCategory(),
            'trends'           => $this->trends($months, $start, $end),
            'overdue'          => $this->overdue($start, $end),
            'utilization'      => $this->utilization($start, $end),
        ];
    }

    /**
     * The chosen period set beside the period of the same length just before it, so each figure says whether it is
     * going up or down. `change` is a percentage, or null when the earlier period had nothing to compare against.
     */
    private function trends(int $months, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $previousEnd = Carbon::instance($start)->subSecond();
        $previousStart = Carbon::instance($start)->subMonths($months);

        $loansNow = $this->loans->countCheckoutsBetween($start, $end);
        $loansBefore = $this->loans->countCheckoutsBetween($previousStart, $previousEnd);

        $visitsNow = array_sum($this->attendanceLogs->countByWeekdayBetween($start, $end)->all());
        $visitsBefore = array_sum($this->attendanceLogs->countByWeekdayBetween($previousStart, $previousEnd)->all());

        $lateNow = (int) $this->loans->lateCountsByMonthBetween($start, $end)->sum();
        $lateBefore = (int) $this->loans->lateCountsByMonthBetween($previousStart, $previousEnd)->sum();

        $trend = fn (int|float $now, int|float $before) => [
            'current'  => $now,
            'previous' => $before,
            'change'   => $before > 0 ? round((($now - $before) / $before) * 100, 1) : null,
        ];

        return [
            'loans'  => $trend($loansNow, $loansBefore),
            'visits' => $trend($visitsNow, $visitsBefore),
            'late'   => $trend($lateNow, $lateBefore),
        ];
    }

    /** Overdue records: what is late right now, how late, how it has moved month to month, and the worst cases. */
    private function overdue(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $aging = $this->loans->overdueAging();
        $late = $this->loans->lateCountsByMonthBetween($start, $end);

        $labels = [];
        $counts = [];
        $cursor = Carbon::instance($start)->startOfMonth();
        while ($cursor->lte(Carbon::instance($end))) {
            $labels[] = $cursor->format('M Y');
            $counts[] = (int) ($late[$cursor->format('Y-m')] ?? 0);
            $cursor->addMonth();
        }

        return [
            'now'     => array_sum(array_column($aging, 'count')),
            'aging'   => $aging,
            'byMonth' => ['labels' => $labels, 'counts' => $counts],
            'oldest'  => $this->loans->overdueWithRelations(5)->map(fn ($loan) => [
                'student'     => $loan->student->user->fullName,
                'memberID'    => $loan->student->memberIDNumber,
                'title'       => $loan->copy->book->title,
                'dueDate'     => $loan->dueDate->toDateString(),
                'daysOverdue' => (int) $loan->dueDate->diffInDays(now()),
            ])->values()->all(),
        ];
    }

    /**
     * How hard the collection is working: the share of lendable copies that are out right now, titles nobody borrowed
     * in the period, and loans per copy by subject (the subjects with the busiest copies first).
     */
    private function utilization(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $status = $this->bookCopies->countsByStatus();
        $out = (int) $status->get('borrowed', 0);
        $lendable = $out + (int) $status->get('available', 0);

        $copies = $this->bookCopies->lendableCopiesBySubject();
        $loans = $this->loans->loansBySubjectBetween($start, $end);

        $bySubject = $copies->map(fn ($copyCount, $name) => [
            'name'    => (string) $name,
            'loans'   => (int) $loans->get($name, 0),
            'copies'  => (int) $copyCount,
            'perCopy' => round($loans->get($name, 0) / max((int) $copyCount, 1), 2),
        ])->sortByDesc('perCopy')->take(8)->values()->all();

        return [
            'rate'               => $lendable > 0 ? round(($out / $lendable) * 100, 1) : null,
            'copiesOut'          => $out,
            'lendableCopies'     => $lendable,
            'neverBorrowedTitles' => $this->bookCopies->titlesNeverBorrowedBetween($start, $end),
            'bySubject'          => $bySubject,
        ];
    }

    private function loansPerMonth(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $monthly = $this->loans->countsByMonthBetween($start, $end);

        $labels = [];
        $checkedOut = [];
        $returned = [];

        $cursor = Carbon::instance($start)->startOfMonth();
        $endCarbon = Carbon::instance($end);

        while ($cursor->lte($endCarbon)) {
            $key = $cursor->format('Y-m');
            $labels[] = $cursor->format('M Y');
            $checkedOut[] = (int) ($monthly['checkedOut'][$key] ?? 0);
            $returned[] = (int) ($monthly['returned'][$key] ?? 0);
            $cursor->addMonth();
        }

        return compact('labels', 'checkedOut', 'returned');
    }

    private function visitsByWeekday(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $weekdayCounts = $this->attendanceLogs->countByWeekdayBetween($start, $end);

        $counts = [];
        foreach (range(1, 7) as $day) {
            $counts[] = (int) ($weekdayCounts[$day] ?? 0);
        }

        return ['labels' => self::WEEKDAY_LABELS, 'counts' => $counts];
    }

    private function topBooks(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->loans->topBooksBetween($start, $end, 5)
            ->map(fn ($row) => ['title' => $row->title, 'count' => (int) $row->total])
            ->values()
            ->all();
    }

    private function booksByCategory(): array
    {
        return $this->subjects->withBooksCount()
            ->map(fn ($subject) => ['name' => $subject->name, 'count' => $subject->books_count])
            ->values()
            ->all();
    }
}
