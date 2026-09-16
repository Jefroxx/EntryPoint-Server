<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\Student;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary()
    {
        return response()->json([
            'totalBooks'   => Book::count(),
            'totalMembers' => Student::where('registrationStatus', 'approved')->count(),
            'activeLoans'  => Loan::where('status', 'Active')->count(),
            'pendingFines' => Penalty::where('paymentStatus', 'Unpaid')->count(),
        ]);
    }

    public function borrowingOverview(Request $request)
    {
        $month = $request->filled('month')
            ? \Carbon\Carbon::parse($request->query('month') . '-01')
            : now();

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $weeks = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $weekEnd = $cursor->copy()->addDays(6)->min($end);
            $weeks[] = ['label' => $cursor->format('M j'), 'start' => $cursor->copy(), 'end' => $weekEnd->copy()];
            $cursor->addDays(7);
        }

        $borrowedByDay = Loan::whereBetween('checkoutDate', [$start, $end])
            ->selectRaw('DATE(checkoutDate) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $returnedByDay = Loan::whereNotNull('returnDate')
            ->whereBetween('returnDate', [$start, $end])
            ->selectRaw('DATE(returnDate) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $labels = [];
        $borrowed = [];
        $returned = [];

        foreach ($weeks as $week) {
            $labels[] = $week['label'];

            $days = collect(\Carbon\CarbonPeriod::create($week['start'], $week['end']))
                ->map(fn($d) => $d->format('Y-m-d'));

            $borrowed[] = $days->sum(fn($d) => $borrowedByDay->get($d, 0));
            $returned[] = $days->sum(fn($d) => $returnedByDay->get($d, 0));
        }

        return response()->json(compact('labels', 'borrowed', 'returned'));
    }

    public function bookStatus()
    {
        $counts = BookCopy::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $breakdown = [
            'available'   => $counts->get('available', 0),
            'borrowed'    => $counts->get('borrowed', 0),
            'maintenance' => $counts->get('damaged', 0),
            'inactive'    => $counts->get('lost', 0) + $counts->get('retired', 0),
        ];

        return response()->json([
            'total'     => array_sum($breakdown),
            'breakdown' => $breakdown,
        ]);
    }

    public function recentLoans()
    {
        $loans = Loan::with(['student.user', 'copy.book'])
            ->orderByDesc('checkoutDate')
            ->limit(5)
            ->get();

        return response()->json($loans->map(function (Loan $loan) {
            return [
                'studentName' => $loan->student->user->getFullNameAttribute(),
                'bookTitle'   => $loan->copy->book->title,
                'checkoutDate' => $loan->checkoutDate->toDateString(),
                'dueDate'     => $loan->dueDate->toDateString(),
                'status'      => $this->loanStatusLabel($loan),
            ];
        }));
    }

    public function overdueLoans()
    {
        $loans = Loan::with(['student.user', 'copy.book'])
            ->where('status', 'Active')
            ->where('dueDate', '<', now())
            ->orderBy('dueDate')
            ->limit(5)
            ->get();

        return response()->json($loans->map(function (Loan $loan) {
            return [
                'studentName'  => $loan->student->user->getFullNameAttribute(),
                'bookTitle'    => $loan->copy->book->title,
                'daysOverdue'  => (int) $loan->dueDate->diffInDays(now()),
            ];
        }));
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
