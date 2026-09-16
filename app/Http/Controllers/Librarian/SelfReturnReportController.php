<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\SelfReturnReport;
use App\Models\SystemNotification;
use Illuminate\Validation\ValidationException;

class SelfReturnReportController extends Controller
{
    public function index()
    {
        $reports = SelfReturnReport::with(['loan.student.user', 'loan.copy.book'])
            ->where('verificationStatus', 'Pending')
            ->orderBy('reportedAt')
            ->get();

        return response()->json(['reports' => $reports]);
    }

    public function verify(SelfReturnReport $report)
    {
        $librarian = request()->user()->librarian;

        try {
            $report->verify($librarian);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['report' => [$e->getMessage()]]);
        }

        $report->load('loan.copy.book');

        SystemNotification::notify(
            $report->loan->studentID,
            "Your return of \"{$report->loan->copy->book->title}\" has been verified. Thank you!",
            'self_return_verified'
        );

        return response()->json(['message' => 'Self-return verified.', 'report' => $report]);
    }

    public function reject(SelfReturnReport $report)
    {
        $librarian = request()->user()->librarian;

        try {
            $report->reject($librarian);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['report' => [$e->getMessage()]]);
        }

        $report->load('loan.copy.book');

        SystemNotification::notify(
            $report->loan->studentID,
            "Your self-return report for \"{$report->loan->copy->book->title}\" could not be verified. Please return the physical copy, or try reporting again after 6 hours.",
            'self_return_rejected'
        );

        return response()->json(['message' => 'Self-return report rejected.', 'report' => $report]);
    }
}
