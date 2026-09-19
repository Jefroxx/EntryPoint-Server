<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWishlistRequest;
use App\Models\Wishlist;
use App\Services\WishlistService;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function __construct(private WishlistService $wishlistService)
    {
    }

    public function index(Request $request)
    {
        $student = $request->user()->student;

        return response()->json(['wishlist' => $this->wishlistService->wishlistIndex($student->studentID)]);
    }

    public function store(StoreWishlistRequest $request)
    {
        $student = $request->user()->student;

        $wishlistItem = $this->wishlistService->addToWishlist($student->studentID, $request->validated()['bookID']);

        return response()->json([
            'message'  => 'Book added to wishlist.',
            'wishlist' => $wishlistItem,
        ], 201);
    }

    public function destroy(Request $request, Wishlist $wishlist)
    {
        $student = $request->user()->student;

        $this->wishlistService->removeFromWishlist($wishlist, $student->studentID);

        return response()->json(['message' => 'Removed from wishlist.']);
    }
}
