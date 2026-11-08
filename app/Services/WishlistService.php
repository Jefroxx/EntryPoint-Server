<?php

namespace App\Services;

use App\Models\Wishlist;
use App\Repositories\Contracts\BookCopyRepositoryInterface;
use App\Repositories\Contracts\LoanRepositoryInterface;
use App\Repositories\Contracts\ReservationRepositoryInterface;
use App\Repositories\Contracts\WishlistRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WishlistService
{
    public function __construct(
        private WishlistRepositoryInterface $wishlists,
        private BookCopyRepositoryInterface $bookCopies,
        private ReservationRepositoryInterface $reservations,
        private LoanRepositoryInterface $loans,
    ) {
    }

    public function cartIndex(int $studentID): Collection
    {
        return $this->wishlists->cartForStudent($studentID);
    }

    public function addToCart(int $studentID, int $bookID): Wishlist
    {
        $item = DB::transaction(function () use ($studentID, $bookID) {
            $currentCartCount = $this->wishlists->cartCountForStudent($studentID, lock: true);
            $existing = $this->wishlists->findByStudentAndBook($studentID, $bookID);

            if ($existing && $existing->inCart) {
                throw ValidationException::withMessages([
                    'bookID' => ['This book is already in your cart.'],
                ]);
            }

            // The cart can't hold more than the reservation slots the student has left (loans and active
            // reservations already use some). Applies to hearted books too: moving one from the wishlist
            // still takes a cart place.
            $slotsLeft = max(
                ReservationService::MAX_ACTIVE_RESERVATIONS
                - $this->reservations->activeCountForStudent($studentID)
                - $this->loans->activeCountForStudent($studentID),
                0,
            );

            if ($currentCartCount >= $slotsLeft) {
                throw ValidationException::withMessages([
                    'bookID' => [$slotsLeft === 0
                        ? 'You have no slots left, so you can\'t add more books to your cart.'
                        : "You only have {$slotsLeft} slot(s) left, so your cart can hold {$slotsLeft} book(s)."],
                ]);
            }

            if ($existing) {
                return $this->wishlists->update($existing, ['inCart' => true]);
            }

            // In the cart only; putting a book in the cart doesn't heart it.
            return $this->wishlists->create([
                'uuid'       => Str::uuid(),
                'studentID'  => $studentID,
                'bookID'     => $bookID,
                'inCart'     => true,
                'inWishlist' => false,
                'addedAt'    => now(),
            ]);
        });

        return $item->load('book');
    }

    public function removeFromCart(Wishlist $wishlist, int $studentID): void
    {
        if ($wishlist->studentID !== $studentID) {
            throw new AuthorizationException('This item does not belong to you.');
        }

        // A hearted book goes back to just being hearted; a cart-only row has nothing left to hold.
        if ($wishlist->inWishlist) {
            $this->wishlists->update($wishlist, ['inCart' => false]);
        } else {
            $this->wishlists->delete($wishlist);
        }
    }

    public function wishlistIndex(int $studentID): Collection
    {
        $rows = $this->wishlists->wishlistForStudent($studentID);

        // What the cart needs to warn "no copy right now": copies free to promise (on the shelf and not held
        // for an accepted reservation) and how many are already waiting in line.
        $queues = $this->reservations->waitingCountsByBook($rows->pluck('bookID')->all());

        return $rows->each(function (Wishlist $row) use ($queues) {
            if (! $row->book) {
                return;
            }

            $free = $this->bookCopies->availableCountForBook($row->bookID) - $this->reservations->acceptedCountForBook($row->bookID);
            $row->book->setAttribute('availableCopies', max(0, $free));
            $row->book->setAttribute('queueLength', $queues[$row->bookID] ?? 0);
        });
    }

    public function addToWishlist(int $studentID, int $bookID): Wishlist
    {
        $existing = $this->wishlists->findByStudentAndBook($studentID, $bookID);

        if ($existing?->inWishlist) {
            throw ValidationException::withMessages([
                'bookID' => ['This book is already in your wishlist.'],
            ]);
        }

        // Already in the cart: the same row now carries the heart too.
        $wishlistItem = $existing
            ? $this->wishlists->update($existing, ['inWishlist' => true])
            : $this->wishlists->create([
                'uuid'       => Str::uuid(),
                'studentID'  => $studentID,
                'bookID'     => $bookID,
                'inWishlist' => true,
                'addedAt'    => now(),
            ]);

        return $wishlistItem->load('book');
    }

    public function removeFromWishlist(Wishlist $wishlist, int $studentID): void
    {
        if ($wishlist->studentID !== $studentID) {
            throw new AuthorizationException('This wishlist item does not belong to you.');
        }

        // Un-hearting a book that's in the cart leaves it in the cart.
        if ($wishlist->inCart) {
            $this->wishlists->update($wishlist, ['inWishlist' => false]);
        } else {
            $this->wishlists->delete($wishlist);
        }
    }
}
