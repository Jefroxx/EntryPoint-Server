<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ResourceController extends Controller
{
    public function index(Request $request)
    {
        $query = Resource::with('activeUsage.student.user');

        if ($request->filled('resourceType')) {
            $query->where('resourceType', $request->query('resourceType'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(['resources' => $query->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'resourceType' => ['required', 'string', 'max:100'],
            'name'         => ['required', 'string', 'max:150'],
        ]);

        $resource = Resource::create([
            'uuid'         => Str::uuid(),
            'resourceType' => $validated['resourceType'],
            'name'         => $validated['name'],
            'status'       => 'Available',
        ]);

        return response()->json([
            'message'  => 'Resource added.',
            'resource' => $resource,
        ], 201);
    }

    public function update(Request $request, Resource $resource)
    {
        $validated = $request->validate([
            'resourceType' => ['sometimes', 'string', 'max:100'],
            'name'         => ['sometimes', 'string', 'max:150'],
            // 'In Use' is set only by starting/ending a usage session, not by a direct edit.
            'status'       => ['sometimes', Rule::in(['Available', 'Unavailable'])],
        ]);

        if (isset($validated['status']) && $resource->status === 'In Use') {
            return response()->json([
                'message' => 'Cannot change availability while the resource is currently in use. End the active session first.',
            ], 422);
        }

        $resource->update($validated);

        return response()->json([
            'message'  => 'Resource updated.',
            'resource' => $resource->fresh(),
        ]);
    }

    public function destroy(Resource $resource)
    {
        if ($resource->status === 'In Use') {
            return response()->json([
                'message' => 'Cannot delete: this resource is currently in use.',
            ], 422);
        }

        $resource->delete();

        return response()->json(['message' => 'Resource removed.']);
    }
}
