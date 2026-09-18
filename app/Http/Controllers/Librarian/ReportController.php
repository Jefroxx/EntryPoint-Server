<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\BookSubject;
use App\Models\Loan;
use App\Models\Penalty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function overview(Request $request)
    {
        $validated = $request->validate([
            'months' => ['nullable', 'integer', 'in:3,6,12'],
        ]);

        $months = $validated['months'] ?? 6;
        $rangeStart = now()->startOfMonth()->subMonths($months - 1);
        $monthStart = now()->startOfMonth();

        return response()->json([
            'kpis' => [
                'loansThisMonth'   => Loan::where('checkoutDate', '>=', $monthStart)->count(),
                'onTimeRate'       => $this->onTimeRate($monthStart),
                'avgVisitMinutes'  => $this->avgVisitMinutes(),
                'finesOutstanding' => (float) Penalty::where('paymentStatus', 'Unpaid')->sum('amount'),
            ],
            'loansPerMonth'   => $this->loansPerMonth($rangeStart, $months),
            'visitsByWeekday' => $this->visitsByWeekday($rangeStart),
            'topBooks'        => $this->topBooks($rangeStart),
            'booksByCategory' => BookSubject::withCount('books')
                ->orderByDesc('books_count')
                ->get(['subjectID', 'name'])
                ->map(fn ($s) => ['name' => $s->name, 'count' => $s->books_count])
                ->values(),
        ]);
    }

    /** Share of this month's returned loans that came back on or before their due date (null if none returned yet). */
    private function onTimeRate($monthStart): ?int
    {
        $returned = Loan::whereNotNull('returnDate')->where('returnDate', '>=', $monthStart);
        $total = (clone $returned)->count();

        if ($total === 0) {
            return null;
        }

        $onTime = (clone $returned)->whereColumn('returnDate', '<=', 'dueDate')->count();

        return (int) round($onTime / $total * 100);
    }

    /** Average completed visit length over the last 30 days, in minutes (null if none). */
    private function avgVisitMinutes(): ?int
    {
        $average = AttendanceLog::whereNotNull('exitTime')
            ->where('entryTime', '>=', now()->subDays(30))
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, entryTime, exitTime)) as minutes')
            ->value('minutes');

        return $average === null ? null : (int) round($average);
    }

    private function loansPerMonth($rangeStart, int $months): array
    {
        $checkedOut = Loan::where('checkoutDate', '>=', $rangeStart)
            ->selectRaw("DATE_FORMAT(checkoutDate, '%Y-%m') as ym, COUNT(*) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $returned = Loan::whereNotNull('returnDate')
            ->where('returnDate', '>=', $rangeStart)
            ->selectRaw("DATE_FORMAT(returnDate, '%Y-%m') as ym, COUNT(*) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $labels = [];
        $out = [];
        $back = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $rangeStart->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $labels[] = $month->format('M');
            $out[] = (int) $checkedOut->get($key, 0);
            $back[] = (int) $returned->get($key, 0);
        }

        return ['labels' => $labels, 'checkedOut' => $out, 'returned' => $back];
    }

    private function visitsByWeekday($rangeStart): array
    {
        // MySQL DAYOFWEEK: 1 = Sunday … 7 = Saturday.
        $counts = AttendanceLog::where('entryTime', '>=', $rangeStart)
            ->selectRaw('DAYOFWEEK(entryTime) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $order = ['Mon' => 2, 'Tue' => 3, 'Wed' => 4, 'Thu' => 5, 'Fri' => 6, 'Sat' => 7, 'Sun' => 1];

        return [
            'labels' => array_keys($order),
            'counts' => array_map(fn ($day) => (int) $counts->get($day, 0), array_values($order)),
        ];
    }

    private function topBooks($rangeStart)
    {
        return DB::table('loans')
            ->join('book_copies', 'loans.copyID', '=', 'book_copies.copyID')
            ->join('books', 'book_copies.bookID', '=', 'books.bookID')
            ->whereNull('books.deleted_at')
            ->where('loans.checkoutDate', '>=', $rangeStart)
            ->selectRaw('books.title as title, COUNT(*) as total')
            ->groupBy('books.bookID', 'books.title')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['title' => $row->title, 'count' => (int) $row->total])
            ->values();
    }
}
