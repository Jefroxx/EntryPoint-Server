<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\MarketItem;
use App\Services\MarketplaceService;
use Illuminate\Http\Request;

class MarketItemController extends Controller
{
    public function __construct(private MarketplaceService $marketplace)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->marketplace->studentIndex($request->user()->student));
    }

    public function redeem(Request $request, MarketItem $item)
    {
        $redemption = $this->marketplace->redeemSingle($item, $request->user()->student);

        return response()->json([
            'message'    => 'Redemption successful.',
            'redemption' => $redemption,
        ], 201);
    }
}
