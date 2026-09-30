<?php

namespace App\Services;

use App\Models\Wishlist;
use App\Repositories\Contracts\WishlistRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WishlistService
{
    private const MAX_CART_ITEMS = 3;

    public function __construct(private WishlistRepositoryInterface $wishlists)
    {
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

            // Applies to hearted books too: moving one from the wishlist still takes a cart slot.
            if ($currentCartCount >= self::MAX_CART_ITEMS) {
                throw ValidationException::withMessages([
                    'bookID' => ['Your cart is full (max ' . self::MAX_CART_ITEMS . ' books).'],
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
        return $this->wishlists->wishlistForStudent($studentID);
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
