<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMarketItemRequest;
use App\Http\Requests\UpdateMarketItemRequest;
use App\Models\MarketItem;
use App\Services\MarketplaceService;

class MarketItemController extends Controller
{
    public function __construct(private MarketplaceService $marketplace)
    {
    }

    public function index()
    {
        return response()->json(['items' => $this->marketplace->listItems()]);
    }

    public function store(StoreMarketItemRequest $request)
    {
        $item = $this->marketplace->createItem($request->validated());

        return response()->json([
            'message' => 'Item added to the point shop.',
            'item'    => $item,
        ], 201);
    }

    public function update(UpdateMarketItemRequest $request, MarketItem $item)
    {
        $item = $this->marketplace->updateItem($item, $request->validated());

        return response()->json([
            'message' => 'Item updated.',
            'item'    => $item,
        ]);
    }

    public function destroy(MarketItem $item)
    {
        $this->marketplace->deleteItem($item);

        return response()->json(['message' => 'Item removed from the point shop.']);
    }
}
