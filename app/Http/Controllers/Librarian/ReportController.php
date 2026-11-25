<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports)
    {
    }

    public function overview(Request $request)
    {
        // A custom range (from and to, as Y-m-d) when given; otherwise the last 3, 6 or 12 months.
        if ($request->filled('from') || $request->filled('to')) {
            $range = $request->validate([
                'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:to'],
                'to'   => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            ]);

            $from = Carbon::parse($range['from'])->startOfDay();
            $to = Carbon::parse($range['to'])->endOfDay();

            // A report over many years is slow to build and impossible to read, so the range stops at two years.
            if ($from->diffInMonths($to) > 24) {
                throw ValidationException::withMessages(['from' => ['Choose a range of two years or less.']]);
            }

            return response()->json($this->reports->overviewBetween($from, $to));
        }

        $months = (int) ($request->query('months') ?? 3);
        $months = in_array($months, [3, 6, 12], true) ? $months : 3;

        return response()->json($this->reports->overview($months));
    }
}
