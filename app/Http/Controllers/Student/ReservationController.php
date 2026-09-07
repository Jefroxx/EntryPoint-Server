<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Librarian;
use App\Models\Reservation;
use App\Models\SystemNotification;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    private const MAX_ACTIVE_RESERVATIONS = 3;

    public function index(Request $request)
    {
        $student = $request->user()->student;

        $reservations = $student->reservations()
            ->with('book.category', 'book.authors')
            ->orderByDesc('reservedAt')
            ->get();

        $reservations->each(function ($reservation) {
            if ($reservation->status === 'Waiting') {
                $reservation->queuePosition = Reservation::where('bookID', $reservation->bookID)
                    ->where('status', 'Waiting')
                    ->where('reservedAt', '<', $reservation->reservedAt)
                    ->count() + 1;
            } else {
                $reservation->queuePosition = null;
            }
        });

        return response()->json(['reservations' => $reservations]);
    }

    public function store(Request $request)
    {
        $student = $request->user()->student;

        $created = DB::transaction(function () use ($student) {
            $cartItems = Wishlist::where('studentID', $student->studentID)
                ->where('inCart', true)
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Your cart is empty. Add books to your cart before reserving.'],
                ]);
            }

            $activeCount = Reservation::where('studentID', $student->studentID)
                ->whereIn('status', ['Waiting', 'Accepted'])
                ->lockForUpdate()
                ->count();

            if ($activeCount + $cartItems->count() > self::MAX_ACTIVE_RESERVATIONS) {
                $slotsLeft = max(self::MAX_ACTIVE_RESERVATIONS - $activeCount, 0);
                throw ValidationException::withMessages([
                    'cart' => ["You can only have " . self::MAX_ACTIVE_RESERVATIONS . " active reservations at a time. You have {$slotsLeft} slot(s) left."],
                ]);
            }

            $bookIDs = $cartItems->pluck('bookID')->toArray();

            $alreadyReserved = Reservation::where('studentID', $student->studentID)
                ->whereIn('status', ['Waiting', 'Accepted'])
                ->whereIn('bookID', $bookIDs)
                ->exists();

            if ($alreadyReserved) {
                throw ValidationException::withMessages([
                    'cart' => ['One or more books in your cart are already in your active reservations.'],
                ]);
            }

            $created = [];
            foreach ($cartItems as $item) {
                $created[] = Reservation::create([
                    'uuid'       => Str::uuid(),
                    'studentID'  => $student->studentID,
                    'bookID'     => $item->bookID,
                    'status'     => 'Waiting',
                    'reservedAt' => now(),
                ]);
            }

            Wishlist::where('studentID', $student->studentID)
                ->where('inCart', true)
                ->delete();

            return $created;
        });

        Librarian::all()->each(function ($librarian) use ($student, $created) {
            SystemNotification::notify(
                $librarian->librarianID,
                "{$student->user->fullName} submitted " . count($created) . " new reservation(s).",
                'new_reservation'
            );
        });

        return response()->json([
            'message'      => 'Reservation(s) submitted.',
            'reservations' => collect($created)->load('book'),
        ], 201);
    }

    public function destroy(Request $request, Reservation $reservation)
    {
        $student = $request->user()->student;

        if ($reservation->studentID !== $student->studentID) {
            return response()->json(['message' => 'This reservation does not belong to you.'], 403);
        }

        if ($reservation->status !== 'Waiting') {
            return response()->json(['message' => 'Only a Waiting reservation can be cancelled.'], 422);
        }

        $reservation->delete();

        return response()->json(['message' => 'Reservation cancelled.']);
    }
}
