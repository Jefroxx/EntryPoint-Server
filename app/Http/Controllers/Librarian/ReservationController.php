<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
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
        ]);
    }

    /**
     * View the queue for a specific book — useful for a librarian to see
     * who's next in line before accepting anyone.
     */
    public function queueForBook(int $bookID)
    {
        return response()->json(['queue' => $this->reservationService->queueForBook($bookID)]);
    }

    public function accept(Reservation $reservation)
    {
        $reservation = $this->reservationService->accept($reservation);

        return response()->json([
            'message'     => 'Reservation accepted. Student may now pick up the book.',
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
