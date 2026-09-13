<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\Reservation;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'studentID'     => ['required', 'integer', 'exists:students,studentID'],
            'copyID'        => ['required', 'integer', 'exists:book_copies,copyID'],
            'reservationID' => ['nullable', 'integer', 'exists:reservations,reservationID'],
        ]);

        $loan = DB::transaction(function () use ($validated) {
            $copy = BookCopy::with('book')->lockForUpdate()->findOrFail($validated['copyID']);

            if ($copy->status !== 'available') {
                throw ValidationException::withMessages([
                    'copyID' => ["This copy is currently '{$copy->status}' and cannot be checked out."],
                ]);
            }

            if (! empty($validated['reservationID'])) {
                $reservation = Reservation::findOrFail($validated['reservationID']);

                if ($reservation->studentID != $validated['studentID']) {
                    throw ValidationException::withMessages([
                        'reservationID' => ['This reservation does not belong to the specified student.'],
                    ]);
                }

                if ($reservation->bookID !== $copy->bookID) {
                    throw ValidationException::withMessages([
                        'reservationID' => ['This reservation is for a different book title.'],
                    ]);
                }

                if ($reservation->status !== 'Accepted') {
                    throw ValidationException::withMessages([
                        'reservationID' => ["Reservation must be 'Accepted' before converting to a loan."],
                    ]);
                }
            }

            $copy->book->assertLoanable();

            $loan = Loan::create([
                'uuid'         => Str::uuid(),
                'studentID'    => $validated['studentID'],
                'copyID'       => $copy->copyID,
                'loanType'     => $copy->book->areasOfLibrary,
                'checkoutDate' => now(),
                'dueDate'      => $copy->book->computeDueDate(),
                'status'       => 'Active',
            ]);

            $copy->update(['status' => 'borrowed']);

            if (! empty($validated['reservationID'])) {
                $reservation->update(['status' => 'Fulfilled']);
            }

            return $loan;
        });

        $loan->load(['student.user', 'copy.book']);

        SystemNotification::notify(
            $loan->studentID,
            "You've checked out \"{$loan->copy->book->title}\". Due back by {$loan->dueDate->format('M d, Y')}.",
            'loan_checkout'
        );

        Librarian::where('librarianID', '!=', $request->user()->librarian->librarianID)
            ->get()
            ->each(function ($librarian) use ($loan) {
                SystemNotification::notify(
                    $librarian->librarianID,
                    "{$loan->student->user->fullName} checked out \"{$loan->copy->book->title}\".",
                    'loan_checked_out'
                );
            });

        return response()->json([
            'message' => 'Book checked out successfully.',
            'loan'    => $loan,
        ], 201);
    }

    /**
     * Librarian-assisted return — the student hands the physical book
     * back at the counter and staff processes it directly (as opposed
     * to Student\LoanController::selfReturn(), which stages a report
     * for later librarian verification).
     */
    public function returnBook(Loan $loan)
    {
        if ($loan->status !== 'Active') {
            return response()->json([
                'message' => "Only an 'Active' loan can be returned.",
            ], 422);
        }

        $loan->markReturned();
        $loan->load(['student.user', 'copy.book']);

        SystemNotification::notify(
            $loan->studentID,
            "Your return of \"{$loan->copy->book->title}\" has been processed. Thank you!",
            'loan_returned'
        );

        return response()->json([
            'message' => 'Book returned successfully.',
            'loan'    => $loan,
        ]);
    }
}
