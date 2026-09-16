<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Librarian;
use App\Models\MarketCartItem;
use App\Models\MarketItem;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketCartController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user()->student;

        $cart = $student->marketCartItems()->with('item')->get();

        return response()->json([
            'points'    => $student->knowledgeScore,
            'cart'      => $cart,
            'total'     => $cart->sum(fn ($line) => $line->item->pointCost * $line->quantity),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'itemID'   => ['required', 'integer', 'exists:market_items,itemID'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ]);

        $student = $request->user()->student;
        $quantity = $validated['quantity'] ?? 1;

        $line = MarketCartItem::where('studentID', $student->studentID)
            ->where('itemID', $validated['itemID'])
            ->first();

        if ($line) {
            $line->update(['quantity' => $line->quantity + $quantity]);
        } else {
            $line = MarketCartItem::create([
                'uuid'      => Str::uuid(),
                'studentID' => $student->studentID,
                'itemID'    => $validated['itemID'],
                'quantity'  => $quantity,
            ]);
        }

        return response()->json([
            'message' => 'Added to cart.',
            'line'    => $line->load('item'),
        ], 201);
    }

    public function update(Request $request, MarketCartItem $cartItem)
    {
        $student = $request->user()->student;

        if ($cartItem->studentID !== $student->studentID) {
            return response()->json(['message' => 'This cart line does not belong to you.'], 403);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $cartItem->update($validated);

        return response()->json([
            'message' => 'Cart updated.',
            'line'    => $cartItem->fresh()->load('item'),
        ]);
    }

    public function destroy(Request $request, MarketCartItem $cartItem)
    {
        $student = $request->user()->student;

        if ($cartItem->studentID !== $student->studentID) {
            return response()->json(['message' => 'This cart line does not belong to you.'], 403);
        }

        $cartItem->delete();

        return response()->json(['message' => 'Removed from cart.']);
    }

    public function checkout(Request $request)
    {
        $student = $request->user()->student;

        $cart = $student->marketCartItems()->with('item')->get();

        if ($cart->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);
        }

        try {
            $redemptions = DB::transaction(function () use ($cart, $student) {
                $redemptions = $cart->map(fn ($line) => $line->item->redeemFor($student, $line->quantity));

                MarketCartItem::whereIn('cartItemID', $cart->pluck('cartItemID'))->delete();

                return $redemptions;
            });
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['cart' => [$e->getMessage()]]);
        }

        $redemptions->each(fn ($r) => $r->load('item'));

        $itemSummary = $redemptions->map(fn ($r) => $r->quantity > 1 ? "{$r->item->name} x{$r->quantity}" : $r->item->name)->join(', ');
        $totalSpent = $redemptions->sum('pointsSpent');

        SystemNotification::notify(
            $student->studentID,
            "You've redeemed {$itemSummary} for {$totalSpent} points total. A librarian will process it shortly.",
            'redemption_requested'
        );

        Librarian::all()->each(function ($librarian) use ($student, $itemSummary) {
            SystemNotification::notify(
                $librarian->librarianID,
                "{$student->user->fullName} redeemed {$itemSummary} - please fulfill.",
                'new_redemption'
            );
        });

        return response()->json([
            'message'     => 'Checkout successful.',
            'redemptions' => $redemptions,
        ], 201);
    }
}
