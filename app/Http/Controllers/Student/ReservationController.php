<?php

namespace App\Http\Controllers\Student;

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
        $student = $request->user()->student;

        return response()->json([
            'reservations' => $this->reservationService->indexForStudent($student->studentID),
        ]);
    }

    public function store(Request $request)
    {
        $student = $request->user()->student;

        $reservations = $this->reservationService->createFromCart($student->studentID, $student->user->fullName);

        return response()->json([
            'message'      => 'Reservation(s) submitted.',
            'reservations' => $reservations,
        ], 201);
    }

    public function destroy(Request $request, Reservation $reservation)
    {
        $student = $request->user()->student;

        $this->reservationService->cancel($reservation, $student->studentID);

        return response()->json(['message' => 'Reservation cancelled.']);
    }
}
