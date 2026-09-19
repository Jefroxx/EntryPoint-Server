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

            if (! $existing && $currentCartCount >= self::MAX_CART_ITEMS) {
                throw ValidationException::withMessages([
                    'bookID' => ['Your cart is full (max ' . self::MAX_CART_ITEMS . ' books).'],
                ]);
            }

            if ($existing) {
                return $this->wishlists->update($existing, ['inCart' => true]);
            }

            return $this->wishlists->create([
                'uuid'      => Str::uuid(),
                'studentID' => $studentID,
                'bookID'    => $bookID,
                'inCart'    => true,
                'addedAt'   => now(),
            ]);
        });

        return $item->load('book');
    }

    public function removeFromCart(Wishlist $wishlist, int $studentID): void
    {
        if ($wishlist->studentID !== $studentID) {
            throw new AuthorizationException('This item does not belong to you.');
        }

        $this->wishlists->update($wishlist, ['inCart' => false]);
    }

    public function wishlistIndex(int $studentID): Collection
    {
        return $this->wishlists->wishlistForStudent($studentID);
    }

    public function addToWishlist(int $studentID, int $bookID): Wishlist
    {
        if ($this->wishlists->existsForStudentAndBook($studentID, $bookID)) {
            throw ValidationException::withMessages([
                'bookID' => ['This book is already in your wishlist.'],
            ]);
        }

        $wishlistItem = $this->wishlists->create([
            'uuid'      => Str::uuid(),
            'studentID' => $studentID,
            'bookID'    => $bookID,
            'addedAt'   => now(),
        ]);

        return $wishlistItem->load('book');
    }

    public function removeFromWishlist(Wishlist $wishlist, int $studentID): void
    {
        if ($wishlist->studentID !== $studentID) {
            throw new AuthorizationException('This wishlist item does not belong to you.');
        }

        $this->wishlists->delete($wishlist);
    }
}
