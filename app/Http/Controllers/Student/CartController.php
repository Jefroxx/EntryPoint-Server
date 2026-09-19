<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCartRequest;
use App\Models\Wishlist;
use App\Services\WishlistService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private WishlistService $wishlistService)
    {
    }

    public function index(Request $request)
    {
        $student = $request->user()->student;

        return response()->json(['cart' => $this->wishlistService->cartIndex($student->studentID)]);
    }

    public function store(StoreCartRequest $request)
    {
        $student = $request->user()->student;

        $item = $this->wishlistService->addToCart($student->studentID, $request->validated()['bookID']);

        return response()->json([
            'message' => 'Added to cart.',
            'item'    => $item,
        ], 201);
    }

    public function destroy(Request $request, Wishlist $wishlist)
    {
        $student = $request->user()->student;

        $this->wishlistService->removeFromCart($wishlist, $student->studentID);

        return response()->json(['message' => 'Removed from cart.']);
    }
}
