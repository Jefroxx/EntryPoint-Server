<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Reservation;
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

            // If converting from a reservation, validate it belongs to this student/book and is accepted
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

            $circulationType = $copy->book->circulationType;
            $dueDays = config("loans.due_days.{$circulationType}", 7);

            $loan = Loan::create([
                'uuid'         => Str::uuid(),
                'studentID'    => $validated['studentID'],
                'copyID'       => $copy->copyID,
                'loanType'     => $circulationType,
                'checkoutDate' => now(),
                'dueDate'      => now()->addDays($dueDays),
                'status'       => 'Active',
            ]);

            $copy->update(['status' => 'borrowed']);

            if (! empty($validated['reservationID'])) {
                $reservation->update(['status' => 'Accepted']); // already accepted; could add a 'Fulfilled' status later if you want that distinction
            }

            return $loan;
        });

        return response()->json([
            'message' => 'Book checked out successfully.',
            'loan'    => $loan->load(['student.user', 'copy.book']),
        ], 201);
    }
}
