<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Reservation;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\WishlistRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        private ReservationRepositoryInterface $reservations,
        private LoanRepositoryInterface $loans,
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

        // How many copies of each book are free to promise, and how many exist; looked up once per book, not per row.
        $stock = [];

        // Lets the librarian UI grey out "Accept" up front, with the reason, instead of failing on click.
        return $reservations->each(function (Reservation $r) use (&$stock) {
            $r->setAttribute('acceptBlock', $r->status === 'Waiting' ? $this->acceptBlock($r) : null);

            $stock[$r->bookID] ??= [
                'free'  => max($this->unheldCopyCount($r->bookID), 0),
                'total' => $this->bookCopies->countActiveForBook($r->bookID),
            ];
            // An author's name is a computed field and isn't sent with the author, so the names go out as plain text.
            $r->book?->setAttribute('authorNames', $r->book->authors->pluck('name')->values()->all());
            $r->setAttribute('copiesFree', $stock[$r->bookID]['free']);
            $r->setAttribute('copiesTotal', $stock[$r->bookID]['total']);
        });
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
            'Expired'   => 'This reservation expired: it was not collected in time. The student can reserve the book again.',
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

            // Books the student holds on loan use up slots too.
            $activeCount = $this->reservations->activeCountForStudent($studentID, lock: true)
                + $this->loans->activeCountForStudent($studentID);

            $max = AppSettings::maxBooks();

            if ($activeCount + $cartItems->count() > $max) {
                $slotsLeft = max($max - $activeCount, 0);
                throw ValidationException::withMessages([
                    'cart' => ['You can only have ' . $max . " active reservations at a time. You have {$slotsLeft} slot(s) left."],
                ]);
            }

            $bookIDs = $cartItems->pluck('bookID')->toArray();

            if ($this->reservations->alreadyReservedForBooks($studentID, $bookIDs)) {
                throw ValidationException::withMessages([
                    'cart' => ['One or more books in your cart are already in your active reservations.'],
                ]);
            }

            // One copy of a title at a time: a book the student has out (any copy of it) can't be reserved again.
            $held = \App\Models\Loan::where('studentID', $studentID)->whereIn('status', ['Active', 'Received'])
                ->whereHas('copy', fn ($copy) => $copy->whereIn('bookID', $bookIDs))->with('copy.book')->first();

            if ($held) {
                throw ValidationException::withMessages([
                    'cart' => ["You already have \"{$held->copy->book->title}\" borrowed. Return it before reserving it again."],
                ]);
            }

            // A book whose every copy is retired can never be lent, so it can't be queued for either.
            $noCopies = Book::whereIn('bookID', $bookIDs)
                ->whereDoesntHave('copies', fn ($copies) => $copies->where('status', '!=', 'retired'))
                ->pluck('title');

            if ($noCopies->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['No copies are available to reserve for: ' . $noCopies->map(fn ($t) => "\"{$t}\"")->join(', ') . '. Remove them from your cart.'],
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
                // First in line means no one else is ahead, so there is nothing to wait behind: a librarian just has to accept it.
                "Reservation for \"{$reservation->book->title}\" submitted - "
                    . ($queuePosition <= 1 ? 'a librarian will review it soon.' : "you're #{$queuePosition} in line."),
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

        $this->reservations->update($reservation, ['status' => 'Accepted', 'acceptedAt' => now()]);
        $reservation->load('book');

        // The copy is held for a limited time (Settings > Reservations): the student is told by when to collect it.
        $hold = AppSettings::describeHours(AppSettings::holdHours());
        $this->notifications->send(
            $reservation->studentID,
            "Your reservation for \"{$reservation->book->title}\" is ready for pickup! Collect it within {$hold} or it will be released to the next student.",
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

        $title = $reservation->book->title;

        $this->reservations->delete($reservation);

        // The earlier "submitted" notice stays in the list, so say plainly that it no longer stands.
        $this->notifications->send(
            $studentID,
            "You cancelled your reservation for \"{$title}\". Reserve it again anytime, but you'd join the back of the queue.",
            'reservation_cancelled'
        );
    }

    /**
     * Accepted reservations that were not collected within the hold period expire: the copy they held is free again,
     * the student is told, and the next in line is told a copy is available. Run hourly (reservations:expire).
     */
    public function expireStale(): int
    {
        $hours = AppSettings::holdHours();

        $stale = Reservation::with('book')
            ->where('status', 'Accepted')
            ->whereNotNull('acceptedAt')
            ->where('acceptedAt', '<=', now()->subHours($hours))
            ->get();

        foreach ($stale as $reservation) {
            $this->reservations->update($reservation, ['status' => 'Expired']);

            $this->notifications->send(
                $reservation->studentID,
                "Your reservation for \"{$reservation->book->title}\" expired because it wasn't collected within " . AppSettings::describeHours($hours) . '. You can reserve it again.',
                'reservation_expired'
            );

            $this->notifyNextInQueue($reservation->bookID);
        }

        return $stale->count();
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
