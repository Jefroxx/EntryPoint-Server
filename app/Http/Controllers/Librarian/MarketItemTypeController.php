<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\MarketItem;
use App\Models\MarketItemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The list of reward types the Rewards Market offers, kept in step with the rewards that use them. */
class MarketItemTypeController extends Controller
{
    public function index()
    {
        $types = MarketItemType::withCount('items')->get()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['types' => $types]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('market_item_types', 'name')]]);

        $type = MarketItemType::create(['uuid' => (string) Str::uuid(), 'name' => trim($data['name'])]);

        return response()->json(['message' => 'Type added.', 'type' => $type->loadCount('items')], 201);
    }

    /** Renaming a type renames it on every reward that has it. */
    public function update(Request $request, MarketItemType $marketItemType)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('market_item_types', 'name')->ignore($marketItemType->typeID, 'typeID')]]);
        $name = trim($data['name']);

        DB::transaction(function () use ($marketItemType, $name) {
            MarketItem::where('type', $marketItemType->name)->update(['type' => $name]);
            $marketItemType->update(['name' => $name]);
        });

        return response()->json(['message' => 'Type renamed.', 'type' => $marketItemType->fresh()->loadCount('items')]);
    }

    public function destroy(MarketItemType $marketItemType)
    {
        if ($marketItemType->items()->exists()) {
            throw ValidationException::withMessages(['type' => ['Rewards still use this type. Change or delete them first.']]);
        }

        $marketItemType->delete();

        return response()->json(['message' => 'Type removed.']);
    }
}
