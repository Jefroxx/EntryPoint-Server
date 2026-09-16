<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\MarketItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketItemController extends Controller
{
    public function index()
    {
        $items = MarketItem::withCount('redemptions')->orderBy('name')->get();

        return response()->json(['items' => $items]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:150'],
            'type'      => ['nullable', 'string', 'max:100'],
            'pointCost' => ['required', 'integer', 'min:0'],
            'stock'     => ['required', 'integer', 'min:0'],
        ]);

        $item = MarketItem::create([
            'uuid'      => Str::uuid(),
            'name'      => $validated['name'],
            'type'      => $validated['type'] ?? null,
            'pointCost' => $validated['pointCost'],
            'stock'     => $validated['stock'],
        ]);

        return response()->json([
            'message' => 'Item added to the point shop.',
            'item'    => $item,
        ], 201);
    }

    public function update(Request $request, MarketItem $item)
    {
        $validated = $request->validate([
            'name'      => ['sometimes', 'string', 'max:150'],
            'type'      => ['sometimes', 'nullable', 'string', 'max:100'],
            'pointCost' => ['sometimes', 'integer', 'min:0'],
            'stock'     => ['sometimes', 'integer', 'min:0'],
        ]);

        $item->update($validated);

        return response()->json([
            'message' => 'Item updated.',
            'item'    => $item->fresh(),
        ]);
    }

    public function destroy(MarketItem $item)
    {
        if ($item->redemptions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete: this item has redemption history. Set stock to 0 to retire it instead.',
            ], 422);
        }

        $item->delete();

        return response()->json(['message' => 'Item removed from the point shop.']);
    }
}
