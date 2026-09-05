<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user()->student;

        $wishlist = $student->wishlists()
            ->with(['book.category', 'book.authors', 'book.copies'])
            ->orderByDesc('addedAt')
            ->get();

        return response()->json(['wishlist' => $wishlist]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bookID' => ['required', 'integer', 'exists:books,bookID'],
        ]);

        $student = $request->user()->student;

        $alreadyExists = Wishlist::where('studentID', $student->studentID)
            ->where('bookID', $validated['bookID'])
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'bookID' => ['This book is already in your wishlist.'],
            ]);
        }

        $wishlistItem = Wishlist::create([
            'uuid'      => Str::uuid(),
            'studentID' => $student->studentID,
            'bookID'    => $validated['bookID'],
            'addedAt'   => now(),
        ]);

        return response()->json([
            'message'  => 'Book added to wishlist.',
            'wishlist' => $wishlistItem->load('book'),
        ], 201);
    }

    public function destroy(Request $request, Wishlist $wishlist)
    {
        $student = $request->user()->student;

        if ($wishlist->studentID !== $student->studentID) {
            return response()->json([
                'message' => 'This wishlist item does not belong to you.',
            ], 403);
        }

        $wishlist->delete();

        return response()->json(['message' => 'Removed from wishlist.']);
    }
}
