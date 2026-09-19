<?php

namespace App\Services;

use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
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
