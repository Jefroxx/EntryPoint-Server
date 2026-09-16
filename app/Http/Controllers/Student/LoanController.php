<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\SystemNotification;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function selfReturn(Loan $loan)
    {
        if ($loan->studentID !== request()->user()->userID) {
            return response()->json(['message' => 'This loan does not belong to you.'], 403);
        }

        try {
            $report = $loan->submitSelfReturn();
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['loan' => [$e->getMessage()]]);
        }

        $loan->load(['student.user', 'copy.book']);

        Librarian::all()->each(function ($librarian) use ($loan) {
            SystemNotification::notify(
                $librarian->librarianID,
                "{$loan->student->user->fullName} reported returning \"{$loan->copy->book->title}\" - please verify.",
                'self_return_reported'
            );
        });

        return response()->json([
            'message' => 'Self-return report submitted. A librarian will verify it shortly.',
            'report'  => $report,
        ], 201);
    }
}
