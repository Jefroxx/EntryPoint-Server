<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Librarian;
use App\Models\MarketItem;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketItemController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user()->student;

        $items = MarketItem::orderBy('pointCost')->get()->map(function (MarketItem $item) use ($student) {
            return [
                'itemID'     => $item->itemID,
                'name'       => $item->name,
                'type'       => $item->type,
                'pointCost'  => $item->pointCost,
                'stock'      => $item->stock,
                'inStock'    => $item->inStock(),
                'affordable' => $student->knowledgeScore >= $item->pointCost,
            ];
        });

        return response()->json([
            'points' => $student->knowledgeScore,
            'items'  => $items,
        ]);
    }

    public function redeem(Request $request, MarketItem $item)
    {
        $student = $request->user()->student;

        try {
            $redemption = DB::transaction(fn () => $item->redeemFor($student));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['item' => [$e->getMessage()]]);
        }

        $redemption->load('item');

        SystemNotification::notify(
            $student->studentID,
            "You've redeemed \"{$redemption->item->name}\" for {$redemption->pointsSpent} points. A librarian will process it shortly.",
            'redemption_requested'
        );

        Librarian::all()->each(function ($librarian) use ($student, $redemption) {
            SystemNotification::notify(
                $librarian->librarianID,
                "{$student->user->fullName} redeemed \"{$redemption->item->name}\" - please fulfill.",
                'new_redemption'
            );
        });

        return response()->json([
            'message'    => 'Redemption successful.',
            'redemption' => $redemption,
        ], 201);
    }
}
