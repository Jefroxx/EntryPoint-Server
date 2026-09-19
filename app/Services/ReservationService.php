<?php

namespace App\Services;

use App\Models\Reservation;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\WishlistRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    private const MAX_ACTIVE_RESERVATIONS = 3;

    public function __construct(
        private ReservationRepositoryInterface $reservations,
        private WishlistRepositoryInterface $wishlists,
        private BookCopyRepositoryInterface $bookCopies,
        private NotificationService $notifications,
    ) {
    }

    public function listAll(?string $status): Collection
    {
        return $this->reservations->listWithFilters($status);
    }

    public function queueForBook(int $bookID): Collection
    {
        return $this->reservations->queueForBook($bookID);
    }

    public function indexForStudent(int $studentID): Collection
    {
        $reservations = $this->reservations->forStudent($studentID);

        $reservations->each(function (Reservation $reservation) {
            $reservation->queuePosition = $reservation->status === 'Waiting'
                ? $this->reservations->queuePositionAheadOf($reservation->bookID, $reservation->reservedAt)
                : null;
        });

        return $reservations;
    }

    public function createFromCart(int $studentID, string $studentFullName): Collection
    {
        $created = DB::transaction(function () use ($studentID) {
            $cartItems = $this->wishlists->lockCartForStudent($studentID);

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Your cart is empty. Add books to your cart before reserving.'],
                ]);
            }

            $activeCount = $this->reservations->activeCountForStudent($studentID, lock: true);

            if ($activeCount + $cartItems->count() > self::MAX_ACTIVE_RESERVATIONS) {
                $slotsLeft = max(self::MAX_ACTIVE_RESERVATIONS - $activeCount, 0);
                throw ValidationException::withMessages([
                    'cart' => ['You can only have ' . self::MAX_ACTIVE_RESERVATIONS . " active reservations at a time. You have {$slotsLeft} slot(s) left."],
                ]);
            }

            $bookIDs = $cartItems->pluck('bookID')->toArray();

            if ($this->reservations->alreadyReservedForBooks($studentID, $bookIDs)) {
                throw ValidationException::withMessages([
                    'cart' => ['One or more books in your cart are already in your active reservations.'],
                ]);
            }

            $created = [];
            foreach ($cartItems as $item) {
                $created[] = $this->reservations->create([
                    'uuid'       => Str::uuid(),
                    'studentID'  => $studentID,
                    'bookID'     => $item->bookID,
                    'status'     => 'Waiting',
                    'reservedAt' => now(),
                ]);
            }

            $this->wishlists->deleteCartForStudent($studentID);

            return $created;
        });

        $this->notifications->notifyAllLibrarians(
            "{$studentFullName} submitted " . count($created) . ' new reservation(s).',
            'new_reservation'
        );

        foreach ($created as $reservation) {
            $queuePosition = $this->reservations->queuePositionAheadOf($reservation->bookID, $reservation->reservedAt);

            $this->notifications->send(
                $studentID,
                "Reservation for \"{$reservation->book->title}\" submitted - you're #{$queuePosition} in line.",
                'reservation_submitted'
            );
        }

        return Collection::make($created)->load('book');
    }

    public function accept(Reservation $reservation): Reservation
    {
        if ($reservation->status !== 'Waiting') {
            throw ValidationException::withMessages([
                'reservation' => ["Only a 'Waiting' reservation can be accepted."],
            ]);
        }

        // Rule 1: must be the earliest Waiting reservation for this book (FIFO)
        $earliestWaiting = $this->reservations->earliestWaitingForBook($reservation->bookID);

        if ($earliestWaiting && $earliestWaiting->reservationID !== $reservation->reservationID) {
            throw ValidationException::withMessages([
                'reservation' => ["Cannot accept out of order. Reservation #{$earliestWaiting->reservationID} for this book is ahead in the queue and must be handled first."],
            ]);
        }

        // Rule 2: an available copy must exist right now
        if (! $this->bookCopies->hasAvailableForBook($reservation->bookID)) {
            throw ValidationException::withMessages([
                'reservation' => ['No available copy of this book right now. Cannot accept the reservation.'],
            ]);
        }

        $this->reservations->update($reservation, ['status' => 'Accepted']);
        $reservation->load('book');

        $this->notifications->send(
            $reservation->studentID,
            "Your reservation for \"{$reservation->book->title}\" is ready for pickup!",
            'reservation_accepted'
        );

        return $reservation->fresh()->load(['student.user', 'book']);
    }

    public function reject(Reservation $reservation): Reservation
    {
        if ($reservation->status !== 'Waiting') {
            throw ValidationException::withMessages([
                'reservation' => ["Only a 'Waiting' reservation can be rejected."],
            ]);
        }

        $this->reservations->update($reservation, ['status' => 'Rejected']);
        $reservation->load('book');

        $this->notifications->send(
            $reservation->studentID,
            "Your reservation for \"{$reservation->book->title}\" was declined.",
            'reservation_rejected'
        );

        return $reservation->fresh()->load(['student.user', 'book']);
    }

    public function cancel(Reservation $reservation, int $studentID): void
    {
        if ($reservation->studentID !== $studentID) {
            throw new AuthorizationException('This reservation does not belong to you.');
        }

        if ($reservation->status !== 'Waiting') {
            throw ValidationException::withMessages([
                'reservation' => ['Only a Waiting reservation can be cancelled.'],
            ]);
        }

        $this->reservations->delete($reservation);
    }

    /**
     * Notify the front-of-queue Waiting reservation for this book that a
     * copy has just become available. Doesn't change reservation status —
     * a librarian still confirms via accept().
     */
    public function notifyNextInQueue(int $bookID): void
    {
        if (! $this->bookCopies->hasAvailableForBook($bookID)) {
            return;
        }

        $next = $this->reservations->queueForBook($bookID)->first();

        if (! $next) {
            return;
        }

        $this->notifications->send(
            $next->studentID,
            "A copy of \"{$next->book->title}\" is now available - it's your turn! Visit the library to claim it.",
            'reservation_turn'
        );
    }
}
