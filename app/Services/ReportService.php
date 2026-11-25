<?php

namespace App\Services;

use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\BookSubjectRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Models\Penalty;
use App\Models\PointRedemption;
use App\Models\Reservation;
use App\Models\ResourceUsageLog;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private const WEEKDAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    public function __construct(
        private LoanRepositoryInterface $loans,
        private AttendanceLogRepositoryInterface $attendanceLogs,
        private PenaltyRepositoryInterface $penalties,
        private BookSubjectRepositoryInterface $subjects,
        private BookCopyRepositoryInterface $bookCopies,
        private StudentRepositoryInterface $students,
    ) {
    }

    /** The last N whole months up to today. */
    public function overview(int $months): array
    {
        return $this->overviewBetween(now()->subMonths($months - 1)->startOfMonth(), now()->endOfDay());
    }

    /** Everything on the Reports page for the days from $start to $end. */
    public function overviewBetween(Carbon $start, Carbon $end): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $avgVisitMinutes = $this->attendanceLogs->averageMinutesBetween($start, $end);

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
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
            'trends'           => $this->trends($start, $end),
            'overdue'          => $this->overdue($start, $end),
            'utilization'      => $this->utilization($start, $end),
            'students'         => $this->studentsByProgram($start, $end),
            'collection'       => $this->collection(),
            'resources'        => $this->resourceUse($start, $end),
            'fines'            => $this->fines($start, $end),
            'reservations'     => $this->reservations($start, $end),
            'rewards'          => $this->rewards($start, $end),
        ];
    }

    /** Students by program: all approved students, and those who visited in the period (five programs, then "Other"). */
    private function studentsByProgram(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $slices = function (Collection $counts): array {
            $sorted = $counts
                ->mapWithKeys(fn ($total, $program) => [($program !== '' && $program !== null) ? $program : 'Not set' => (int) $total])
                ->sortDesc();

            $top = $sorted->take(5)->map(fn ($count, $label) => ['label' => (string) $label, 'count' => $count])->values();
            $rest = $sorted->slice(5)->sum();
            if ($rest > 0) {
                $top->push(['label' => 'Other', 'count' => $rest]);
            }

            return ['total' => $sorted->sum(), 'slices' => $top->all()];
        };

        return [
            'members'  => $slices($this->students->approvedCountByProgram()),
            'visitors' => $slices($this->attendanceLogs->visitorsByProgramBetween($start, $end)),
        ];
    }

    /** Every physical copy, by where it is now. */
    private function collection(): array
    {
        $counts = $this->bookCopies->countsByStatus();
        $breakdown = [
            'available'   => (int) $counts->get('available', 0),
            'borrowed'    => (int) $counts->get('borrowed', 0),
            'maintenance' => (int) $counts->get('damaged', 0) + (int) $counts->get('maintenance', 0),
            'inactive'    => (int) $counts->get('lost', 0) + (int) $counts->get('retired', 0) + (int) $counts->get('inactive', 0),
        ];

        return ['total' => array_sum($breakdown), 'breakdown' => $breakdown];
    }

    /** Computers, rooms and the like (the Resources page): sessions and the hours they ran, by type. */
    private function resourceUse(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $rows = DB::table('resource_usage_logs as u')
            ->join('resources as r', 'r.resID', '=', 'u.resID')
            ->whereBetween('u.startTime', [$start, $end])
            ->groupBy('r.resourceType')
            ->selectRaw('r.resourceType as type, COUNT(*) as sessions, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, u.startTime, u.endTime)), 0) as minutes')
            ->get();

        $byType = $rows->map(fn ($row) => ['type' => (string) $row->type, 'sessions' => (int) $row->sessions, 'minutes' => (int) $row->minutes])
            ->sortByDesc('sessions')->values()->all();

        return [
            'sessions' => array_sum(array_column($byType, 'sessions')),
            'minutes'  => array_sum(array_column($byType, 'minutes')),
            'byType'   => $byType,
        ];
    }

    /** Fines: what was collected and what was waived in the period, and what is still owed (all of it, whenever it was issued). */
    private function fines(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $settled = fn (string $status) => Penalty::where('paymentStatus', $status)->whereBetween('settledAt', [$start, $end]);

        return [
            'collected'      => (float) $settled('Paid')->sum('amount'),
            'collectedCount' => $settled('Paid')->count(),
            'waived'         => (float) $settled('Waived')->sum('amount'),
            'waivedCount'    => $settled('Waived')->count(),
            'outstanding'    => $this->penalties->stats()['unpaidTotal'],
            'outstandingCount' => $this->penalties->stats()['unpaidCount'],
        ];
    }

    /** Reservations made in the period, and how each one stands now. */
    private function reservations(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $byStatus = Reservation::whereBetween('reservedAt', [$start, $end])
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $statuses = collect(['Waiting', 'Accepted', 'Fulfilled', 'Rejected', 'Expired'])
            ->map(fn ($status) => ['status' => $status, 'count' => (int) $byStatus->get($status, 0)])->all();

        return ['total' => array_sum(array_column($statuses, 'count')), 'byStatus' => $statuses];
    }

    /** Rewards students redeemed in the period (cancelled ones were refunded, so they are counted apart). */
    private function rewards(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $made = PointRedemption::whereBetween('redeemedAt', [$start, $end])->where('fulfillmentStatus', '!=', 'Cancelled');

        return [
            'redemptions' => (clone $made)->count(),
            'pointsSpent' => (int) (clone $made)->sum('pointsSpent'),
            'pending'     => (clone $made)->where('fulfillmentStatus', 'Pending')->count(),
            'cancelled'   => PointRedemption::whereBetween('redeemedAt', [$start, $end])->where('fulfillmentStatus', 'Cancelled')->count(),
        ];
    }

    /**
     * The chosen period set beside the period of the same length just before it, so each figure says whether it is
     * going up or down. `change` is a percentage, or null when the earlier period had nothing to compare against.
     */
    private function trends(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        // The period just before, of exactly the same length.
        $length = (int) Carbon::instance($start)->diffInSeconds(Carbon::instance($end));
        $previousEnd = Carbon::instance($start)->subSecond();
        $previousStart = $previousEnd->copy()->subSeconds($length);

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
        ])->sortByDesc('perCopy')->values()->all();

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
