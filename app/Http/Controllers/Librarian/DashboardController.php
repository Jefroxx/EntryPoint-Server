<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Services\AnnouncementService;
use App\Services\DashboardService;
use App\Services\HallOfFameService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard)
    {
    }

    /**
     * Everything the Dashboard shows, in one answer. The page polls while it is open, so one request instead of
     * five saves four rounds of sign-in checks on every tick. (The single-purpose routes below stay for other screens.)
     */
    public function overview(HallOfFameService $hallOfFame, AnnouncementService $announcements)
    {
        return response()->json([
            'today'         => $this->dashboard->today(),
            'recentLoans'   => $this->dashboard->recentLoans(),
            'overdueLoans'  => $this->dashboard->overdueLoans(),
            'hallOfFame'    => $hallOfFame->staffBoard(),
            'announcements' => $announcements->forSide('staff'),
        ]);
    }

    /** The Dashboard's Trends section in one answer: the totals, the collection, and the month's chart and programs. */
    public function trends(Request $request)
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? null;

        return response()->json([
            'summary'           => $this->dashboard->summary(),
            'bookStatus'        => $this->dashboard->bookStatus(),
            'borrowingOverview' => $this->dashboard->borrowingOverview($month),
            'demographics'      => [
                'members'  => $this->dashboard->demographics('members', null),
                'visitors' => $this->dashboard->demographics('visitors', $month),
            ],
        ]);
    }

    public function hallOfFame(HallOfFameService $hallOfFame)
    {
        return response()->json($hallOfFame->staffBoard());
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
