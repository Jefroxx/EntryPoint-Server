<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\CirculationService;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    public function __construct(private CirculationService $circulation)
    {
    }

    /**
     * A digital copy of the checkout receipt, for when the paper one is lost. Only the student's own
     * loans; anyone else's answers "not found", so loan numbers can't be probed.
     */
    public function receipt(Request $request, Loan $loan)
    {
        abort_unless($loan->studentID === $request->user()->student->studentID, 404);

        return response()->json(['receipt' => $this->circulation->receipt($loan)]);
    }

    public function selfReturn(Request $request, Loan $loan)
    {
        $report = $this->circulation->studentSubmitSelfReturn(
            $loan,
            $request->user()->student->studentID
        );

        return response()->json([
            'message' => 'Self-return report submitted. A librarian will verify it shortly.',
            'report'  => $report,
        ], 201);
    }
}
