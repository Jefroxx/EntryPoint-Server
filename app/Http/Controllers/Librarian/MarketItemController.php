<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMarketItemRequest;
use App\Http\Requests\UpdateMarketItemRequest;
use App\Models\MarketItem;
use App\Services\MarketplaceService;
use Illuminate\Http\Request;

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

    public function storePhoto(Request $request, MarketItem $item)
    {
        $request->validate([
            // The browser shrinks photos to well under this before sending them.
            'photo' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        return response()->json([
            'message' => 'Photo saved.',
            'item'    => $this->marketplace->setItemPhoto($item, $request->file('photo')),
        ]);
    }

    public function destroyPhoto(MarketItem $item)
    {
        return response()->json([
            'message' => 'Photo removed.',
            'item'    => $this->marketplace->removeItemPhoto($item),
        ]);
    }
}
