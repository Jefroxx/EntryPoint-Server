<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\ResourceService;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function __construct(private ResourceService $resourceService)
    {
    }

    /**
     * Real-time availability list — students can browse this to plan a
     * visit but cannot start/end sessions themselves (librarian-mediated).
     */
    public function index(Request $request)
    {
        return response()->json([
            'resources' => $this->resourceService->listForStudent($request->query('resourceType')),
        ]);
    }
}
