<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Reservation;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search'  => ['nullable', 'string', 'max:255'],
            'status'  => ['nullable', 'string', 'in:active,overdue,returned'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? 'active';

        $loans = Loan::with(['student.user', 'copy.book'])
            ->when($status === 'active', fn ($q) => $q->where('status', 'Active'))
            ->when($status === 'overdue', fn ($q) => $q->where('status', 'Active')->where('dueDate', '<', now()))
            ->when($status === 'returned', fn ($q) => $q->where('status', 'Returned'))
            ->when($validated['search'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->whereHas('student.user', fn ($u) => $u
                            ->where('firstName', 'like', "%{$term}%")
                            ->orWhere('lastName', 'like', "%{$term}%"))
                        ->orWhereHas('copy.book', fn ($b) => $b->where('title', 'like', "%{$term}%"));
                });
            })
            ->when(
                $status === 'returned',
                fn ($q) => $q->orderByDesc('returnDate'),
                fn ($q) => $q->orderBy('dueDate'),
            )
            ->paginate($validated['perPage'] ?? 15);

        return response()->json($loans);
    }

    public function stats()
    {
        $active = fn () => Loan::where('status', 'Active');

        return response()->json([
            'active'  => $active()->count(),
            'dueSoon' => $active()->where('dueDate', '>=', now())->where('dueDate', '<=', now()->addDay())->count(),
            'overdue' => $active()->where('dueDate', '<', now())->count(),
        ]);
    }

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

            $copy->book->assertLoanable();

            $loan = Loan::create([
                'uuid'         => Str::uuid(),
                'studentID'    => $validated['studentID'],
                'copyID'       => $copy->copyID,
                'loanType'     => $copy->book->circulationType,
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
