<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\PointRedemption;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PointRedemptionController extends Controller
{
    public function index(Request $request)
    {
        $query = PointRedemption::with(['student.user', 'item'])->orderByDesc('redeemedAt');

        if ($request->filled('fulfillmentStatus')) {
            $query->where('fulfillmentStatus', $request->query('fulfillmentStatus'));
        }

        return response()->json(['redemptions' => $query->get()]);
    }

    public function fulfill(PointRedemption $redemption)
    {
        if ($redemption->fulfillmentStatus !== 'Pending') {
            return response()->json([
                'message' => "Only a 'Pending' redemption can be fulfilled.",
            ], 422);
        }

        $redemption->update(['fulfillmentStatus' => 'Fulfilled']);
        $redemption->load('item');

        SystemNotification::notify(
            $redemption->studentID,
            "Your redemption of \"{$redemption->item->name}\" has been fulfilled. Enjoy!",
            'redemption_fulfilled'
        );

        return response()->json([
            'message'    => 'Redemption fulfilled.',
            'redemption' => $redemption,
        ]);
    }

    /**
     * Cancel a pending redemption — refunds the points and restocks the
     * item, e.g. if the physical reward turned out to be unavailable.
     */
    public function cancel(PointRedemption $redemption)
    {
        if ($redemption->fulfillmentStatus !== 'Pending') {
            return response()->json([
                'message' => "Only a 'Pending' redemption can be cancelled.",
            ], 422);
        }

        DB::transaction(function () use ($redemption) {
            $redemption->update(['fulfillmentStatus' => 'Cancelled']);

            $redemption->item()->increment('stock', $redemption->quantity);

            $student = $redemption->student;
            $student->update(['knowledgeScore' => $student->knowledgeScore + $redemption->pointsSpent]);
        });

        $redemption->load('item');

        SystemNotification::notify(
            $redemption->studentID,
            "Your redemption of \"{$redemption->item->name}\" was cancelled and your {$redemption->pointsSpent} points have been refunded.",
            'redemption_cancelled'
        );

        return response()->json([
            'message'    => 'Redemption cancelled and refunded.',
            'redemption' => $redemption,
        ]);
    }
}
