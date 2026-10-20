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

    /** The "Verify" / "Reject" window: the loan, book and student, and the late fee verifying it now would charge. */
    public function preview(SelfReturnReport $report)
    {
        return response()->json(['preview' => $this->circulation->selfReturnPreview($report)]);
    }

    /** Verifies the report (the book is returned). `finePaid` says the student paid the late fee at the desk. */
    public function verify(Request $request, SelfReturnReport $report)
    {
        $data = $request->validate([
            'finePaid'      => ['sometimes', 'boolean'],
            // The cashier's receipt number is required whenever the late fee is collected with the return.
            'receiptNumber' => ['required_if:finePaid,true,1', 'nullable', 'string', 'max:50'],
        ]);

        $report = $this->circulation->verifySelfReturn($report, $request->user()->librarian->librarianID, (bool) ($data['finePaid'] ?? false), $data['receiptNumber'] ?? null);
        $penalty = $report->loan->penalties()->latest('computedAt')->first();

        return response()->json([
            'message' => match (true) {
                ! $penalty                         => 'Self-return verified.',
                $penalty->paymentStatus === 'Paid' => 'Self-return verified. The late fee was marked as paid.',
                default                            => 'Self-return verified. The late fee is still unpaid.',
            },
            'report'  => $report,
            'penalty' => $penalty?->only(['amount', 'paymentStatus']),
        ]);
    }

    public function reject(Request $request, SelfReturnReport $report)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $report = $this->circulation->rejectSelfReturn($report, $request->user()->librarian->librarianID, $data['reason']);

        return response()->json(['message' => 'Self-return report rejected.', 'report' => $report]);
    }
}
