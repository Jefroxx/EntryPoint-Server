<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\Reservation;
use App\Models\SystemNotification;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');

        $query = Reservation::with(['student.user', 'book'])
            ->orderBy('reservedAt');

        if ($status) {
            $query->where('status', $status);
        }

        return response()->json(['reservations' => $query->get()]);
    }

    /**
     * View the queue for a specific book — useful for a librarian to see
     * who's next in line before accepting anyone.
     */
    public function queueForBook(int $bookID)
    {
        $queue = Reservation::where('bookID', $bookID)
            ->where('status', 'Waiting')
            ->with(['student.user'])
            ->orderBy('reservedAt')
            ->get();

        return response()->json(['queue' => $queue]);
    }

    public function accept(Reservation $reservation)
    {
        if ($reservation->status !== 'Waiting') {
            return response()->json([
                'message' => "Only a 'Waiting' reservation can be accepted.",
            ], 422);
        }

        // Rule 1: must be the earliest Waiting reservation for this book (FIFO)
        $earliestWaiting = Reservation::where('bookID', $reservation->bookID)
            ->where('status', 'Waiting')
            ->orderBy('reservedAt')
            ->first();

        if ($earliestWaiting && $earliestWaiting->reservationID !== $reservation->reservationID) {
            return response()->json([
                'message' => "Cannot accept out of order. Reservation #{$earliestWaiting->reservationID} for this book is ahead in the queue and must be handled first.",
            ], 422);
        }

        // Rule 2: an available copy must exist right now
        $hasAvailableCopy = BookCopy::where('bookID', $reservation->bookID)
            ->where('status', 'available')
            ->exists();

        if (! $hasAvailableCopy) {
            return response()->json([
                'message' => 'No available copy of this book right now. Cannot accept the reservation.',
            ], 422);
        }

        $reservation->update(['status' => 'Accepted']);
        $reservation->load('book');

        SystemNotification::notify(
            $reservation->studentID,
            "Your reservation for \"{$reservation->book->title}\" is ready for pickup!",
            'reservation_accepted'
        );

        return response()->json([
            'message'     => 'Reservation accepted. Student may now pick up the book.',
            'reservation' => $reservation->fresh()->load(['student.user', 'book']),
        ]);
    }

    public function reject(Reservation $reservation)
    {
        if ($reservation->status !== 'Waiting') {
            return response()->json([
                'message' => "Only a 'Waiting' reservation can be rejected.",
            ], 422);
        }

        $reservation->update(['status' => 'Rejected']);
        $reservation->load('book');

        SystemNotification::notify(
            $reservation->studentID,
            "Your reservation for \"{$reservation->book->title}\" was declined.",
            'reservation_rejected'
        );

        return response()->json([
            'message'     => 'Reservation rejected.',
            'reservation' => $reservation->fresh()->load(['student.user', 'book']),
        ]);
    }
}
