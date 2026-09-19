<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMarketCartRequest;
use App\Http\Requests\UpdateMarketCartRequest;
use App\Models\MarketCartItem;
use App\Services\MarketplaceService;
use Illuminate\Http\Request;

class MarketCartController extends Controller
{
    public function __construct(private MarketplaceService $marketplace)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->marketplace->cartIndex($request->user()->student));
    }

    public function store(StoreMarketCartRequest $request)
    {
        $validated = $request->validated();
        $student = $request->user()->student;

        $line = $this->marketplace->addToCart($student, $validated['itemID'], $validated['quantity'] ?? 1);

        return response()->json([
            'message' => 'Added to cart.',
            'line'    => $line,
        ], 201);
    }

    public function update(UpdateMarketCartRequest $request, MarketCartItem $cartItem)
    {
        $student = $request->user()->student;

        $line = $this->marketplace->updateCartLine($cartItem, $student->studentID, $request->validated()['quantity']);

        return response()->json([
            'message' => 'Cart updated.',
            'line'    => $line,
        ]);
    }

    public function destroy(Request $request, MarketCartItem $cartItem)
    {
        $student = $request->user()->student;

        $this->marketplace->removeCartLine($cartItem, $student->studentID);

        return response()->json(['message' => 'Removed from cart.']);
    }

    public function checkout(Request $request)
    {
        $redemptions = $this->marketplace->checkout($request->user()->student);

        return response()->json([
            'message'     => 'Checkout successful.',
            'redemptions' => $redemptions,
        ], 201);
    }
}
