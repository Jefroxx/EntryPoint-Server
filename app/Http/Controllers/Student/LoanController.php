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
