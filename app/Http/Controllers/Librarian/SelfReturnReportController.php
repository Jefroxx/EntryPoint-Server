<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\SelfReturnReport;
use App\Services\CirculationService;
use Illuminate\Http\Request;

class SelfReturnReportController extends Controller
{
    public function __construct(private CirculationService $circulation)
    {
    }

    public function index()
    {
        return response()->json(['reports' => $this->circulation->pendingSelfReturnReports()]);
    }

    public function verify(Request $request, SelfReturnReport $report)
    {
        $report = $this->circulation->verifySelfReturn($report, $request->user()->librarian->librarianID);

        return response()->json(['message' => 'Self-return verified.', 'report' => $report]);
    }

    public function reject(Request $request, SelfReturnReport $report)
    {
        $report = $this->circulation->rejectSelfReturn($report, $request->user()->librarian->librarianID);

        return response()->json(['message' => 'Self-return report rejected.', 'report' => $report]);
    }
}
