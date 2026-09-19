<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Models\Resource;
use App\Services\ResourceService;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function __construct(private ResourceService $resourceService)
    {
    }

    public function index(Request $request)
    {
        $resources = $this->resourceService->listForLibrarian(
            $request->query('resourceType'),
            $request->query('status')
        );

        return response()->json(['resources' => $resources]);
    }

    public function store(StoreResourceRequest $request)
    {
        $resource = $this->resourceService->create($request->validated());

        return response()->json([
            'message'  => 'Resource added.',
            'resource' => $resource,
        ], 201);
    }

    public function update(UpdateResourceRequest $request, Resource $resource)
    {
        $resource = $this->resourceService->update($resource, $request->validated());

        return response()->json([
            'message'  => 'Resource updated.',
            'resource' => $resource,
        ]);
    }

    public function destroy(Resource $resource)
    {
        $this->resourceService->delete($resource);

        return response()->json(['message' => 'Resource removed.']);
    }
}
