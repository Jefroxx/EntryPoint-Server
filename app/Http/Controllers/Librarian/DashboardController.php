<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Book;
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

        $labels = [];
        $borrowed = [];
        $returned = [];

        foreach ($weeks as $week) {
            $labels[] = $week['label'];
            $borrowed[] = Loan::whereBetween('checkoutDate', [$week['start'], $week['end']])->count();
            $returned[] = Loan::whereNotNull('returnDate')
                ->whereBetween('returnDate', [$week['start'], $week['end']])
                ->count();
        }

        return response()->json(compact('labels', 'borrowed', 'returned'));
    }
}
