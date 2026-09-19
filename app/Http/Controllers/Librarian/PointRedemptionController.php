<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\PointRedemption;
use App\Services\MarketplaceService;
use Illuminate\Http\Request;

class PointRedemptionController extends Controller
{
    public function __construct(private MarketplaceService $marketplace)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'redemptions' => $this->marketplace->listRedemptions($request->query('fulfillmentStatus')),
        ]);
    }

    public function fulfill(PointRedemption $redemption)
    {
        $redemption = $this->marketplace->fulfillRedemption($redemption);

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
        $redemption = $this->marketplace->cancelRedemption($redemption);

        return response()->json([
            'message'    => 'Redemption cancelled and refunded.',
            'redemption' => $redemption,
        ]);
    }
}
