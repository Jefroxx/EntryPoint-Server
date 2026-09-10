<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    private const MAX_CART_ITEMS = 3;

    public function index(Request $request)
    {
        $student = $request->user()->student;

        $cart = Wishlist::where('studentID', $student->studentID)
            ->where('inCart', true)
            ->with('book.category', 'book.authors')
            ->get();

        return response()->json(['cart' => $cart]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bookID' => ['required', 'integer', 'exists:books,bookID'],
        ]);

        $student = $request->user()->student;

        $item = DB::transaction(function () use ($validated, $student) {
            $currentCartCount = Wishlist::where('studentID', $student->studentID)
                ->where('inCart', true)
                ->lockForUpdate()
                ->count();

            $existing = Wishlist::where('studentID', $student->studentID)
                ->where('bookID', $validated['bookID'])
                ->first();

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
                $existing->update(['inCart' => true]);
                return $existing;
            }

            return Wishlist::create([
                'uuid'      => Str::uuid(),
                'studentID' => $student->studentID,
                'bookID'    => $validated['bookID'],
                'inCart'    => true,
                'addedAt'   => now(),
            ]);
        });

        return response()->json([
            'message' => 'Added to cart.',
            'item'    => $item->load('book'),
        ], 201);
    }

    public function destroy(Request $request, Wishlist $wishlist)
    {
        $student = $request->user()->student;

        if ($wishlist->studentID !== $student->studentID) {
            return response()->json(['message' => 'This item does not belong to you.'], 403);
        }

        $wishlist->update(['inCart' => false]);

        return response()->json(['message' => 'Removed from cart.']);
    }
}
