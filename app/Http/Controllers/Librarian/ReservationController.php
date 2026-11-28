<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(private ReservationService $reservationService)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'reservations' => $this->reservationService->listAll($request->query('status')),
            // The most books one student can hold: the cards are sized for that many.
            'maxBooks'     => \App\Services\AppSettings::maxBooks(),
        ]);
    }

    /**
     * View the queue for a specific book — useful for a librarian to see
     * who's next in line before accepting anyone.
     */
    public function queueForBook(Book $book)
    {
        return response()->json(['queue' => $this->reservationService->queueForBook($book->bookID)]);
    }

    /** Looks up an accepted reservation from the code on the student's pickup slip. */
    public function lookup(string $code)
    {
        $reservation = $this->reservationService->findByPickupCode($code);

        // Everything the pickup window needs in one answer: the student and book come loaded with the reservation, and
        // the copies on the shelf are listed so a librarian whose scanner won't read a label can pick the number instead.
        $shelfCopies = $reservation->book
            ? $reservation->book->copies()->where('status', 'available')->orderBy('accessionNumber')
                ->get(['copyID', 'accessionNumber'])
            : collect();

        return response()->json(['reservation' => $reservation, 'shelfCopies' => $shelfCopies]);
    }

    public function accept(Reservation $reservation)
    {
        $reservation = $this->reservationService->accept($reservation);

        return response()->json([
            'message'     => 'Reservation accepted. Student may now pick up the book.',
            'reservation' => $reservation,
        ]);
    }

    public function cancel(Reservation $reservation)
    {
        $reservation = $this->reservationService->cancelAccepted($reservation);

        return response()->json([
            'message'     => 'Reservation cancelled. The held copy is back on the shelf.',
            'reservation' => $reservation,
        ]);
    }

    public function reject(Reservation $reservation)
    {
        $reservation = $this->reservationService->reject($reservation);

        return response()->json([
            'message'     => 'Reservation rejected.',
            'reservation' => $reservation,
        ]);
    }
}
