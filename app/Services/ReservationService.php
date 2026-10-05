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
    public const MAX_ACTIVE_RESERVATIONS = 3;

    public function __construct(
        private ReservationRepositoryInterface $reservations,
        private WishlistRepositoryInterface $wishlists,
        private BookCopyRepositoryInterface $bookCopies,
        private NotificationService $notifications,
    ) {
    }

    /**
     * Whether a copy of the book is free to promise to someone. An accepted reservation holds a copy
     * until it is collected, so those copies are not counted as available.
     */
    public function hasUnheldCopy(int $bookID): bool
    {
        return $this->unheldCopyCount($bookID) > 0;
    }

    /**
     * Why a Waiting reservation can't be accepted right now, or null if it can. First come, first served:
     * with N free copies only the first N people in line may be accepted.
     */
    private function acceptBlock(Reservation $reservation): ?string
    {
        $free = $this->unheldCopyCount($reservation->bookID);

        if ($free <= 0) {
            return 'No copy of this book is available right now.';
        }

        // Despite its name this returns the person's place in line (1 = first), not how many are ahead.
        $position = $this->reservations->queuePositionAheadOf($reservation->bookID, $reservation->reservedAt);

        if ($position > $free) {
            return "Not their turn yet: they're #{$position} in line and only {$free} " . ($free === 1 ? 'copy is' : 'copies are') . ' free.';
        }

        return null;
    }

    private function unheldCopyCount(int $bookID): int
    {
        return $this->bookCopies->availableCountForBook($bookID) - $this->reservations->acceptedCountForBook($bookID);
    }

    public function listAll(?string $status): Collection
    {
        $reservations = $this->reservations->listWithFilters($status);

        // Lets the librarian UI grey out "Accept" up front, with the reason, instead of failing on click.
        return $reservations->each(fn (Reservation $r) => $r->setAttribute(
            'acceptBlock',
            $r->status === 'Waiting' ? $this->acceptBlock($r) : null,
        ));
    }

    /**
     * Resolves a pickup slip's code to the reservation behind it, for the checkout desk. Only an
     * Accepted reservation can be collected, so any other state is explained instead of returned.
     */
    public function findByPickupCode(string $code): Reservation
    {
        $reservation = preg_match('/^R-(\d{1,9})$/i', trim($code), $m)
            ? Reservation::with(['student.user', 'book'])->find((int) $m[1])
            : null;

        if (! $reservation) {
            throw ValidationException::withMessages(['code' => ["No reservation matches that pickup code."]]);
        }

        $problem = match ($reservation->status) {
            'Waiting'   => 'This reservation has not been accepted yet.',
            'Rejected'  => 'This reservation was rejected.',
            'Fulfilled' => 'This reservation has already been collected.',
            default     => null,
        };

        if ($problem) {
            throw ValidationException::withMessages(['code' => [$problem]]);
        }

        return $reservation;
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

        // First come, first served: with N free copies, only the first N people in line can be accepted.
        if ($block = $this->acceptBlock($reservation)) {
            throw ValidationException::withMessages(['reservation' => [$block]]);
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
        if (! $this->hasUnheldCopy($bookID)) {
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
