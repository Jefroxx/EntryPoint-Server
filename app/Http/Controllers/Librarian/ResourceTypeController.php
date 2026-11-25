<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The list of resource types the Resources page offers, and keeps in step with the resources that use them. */
class ResourceTypeController extends Controller
{
    public function index()
    {
        $types = ResourceType::withCount('resources')->get()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['types' => $types]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('resource_types', 'name')]]);

        $type = ResourceType::create(['uuid' => (string) Str::uuid(), 'name' => trim($data['name'])]);

        return response()->json(['message' => 'Type added.', 'type' => $type->loadCount('resources')], 201);
    }

    /** Renaming a type renames it on every resource that has it. */
    public function update(Request $request, ResourceType $resourceType)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('resource_types', 'name')->ignore($resourceType->typeID, 'typeID')]]);
        $name = trim($data['name']);

        DB::transaction(function () use ($resourceType, $name) {
            Resource::where('resourceType', $resourceType->name)->update(['resourceType' => $name]);
            $resourceType->update(['name' => $name]);
        });

        return response()->json(['message' => 'Type renamed.', 'type' => $resourceType->fresh()->loadCount('resources')]);
    }

    public function destroy(ResourceType $resourceType)
    {
        if ($resourceType->resources()->exists()) {
            throw ValidationException::withMessages(['type' => ['Resources still use this type. Change or delete them first.']]);
        }

        $resourceType->delete();

        return response()->json(['message' => 'Type removed.']);
    }
}
