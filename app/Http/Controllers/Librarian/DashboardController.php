<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    public function today()
    {
        return response()->json($this->dashboard->today());
    }

    public function summary()
    {
        return response()->json($this->dashboard->summary());
    }

    public function borrowingOverview(Request $request)
    {
        return response()->json($this->dashboard->borrowingOverview($request->query('month')));
    }

    public function demographics(Request $request)
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'in:members,visitors'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        return response()->json($this->dashboard->demographics($validated['scope'] ?? 'members', $validated['month'] ?? null));
    }

    public function bookStatus()
    {
        return response()->json($this->dashboard->bookStatus());
    }

    public function recentLoans()
    {
        return response()->json($this->dashboard->recentLoans());
    }

    public function overdueLoans()
    {
        return response()->json($this->dashboard->overdueLoans());
    }
}
