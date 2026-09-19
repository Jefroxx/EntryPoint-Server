<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceUsageLogRequest;
use App\Models\ResourceUsageLog;
use App\Services\ResourceService;
use Illuminate\Http\Request;

class ResourceUsageLogController extends Controller
{
    public function __construct(private ResourceService $resourceService)
    {
    }

    public function index(Request $request)
    {
        $usageLogs = $this->resourceService->listUsageLogs(
            $request->query('resID'),
            $request->boolean('active')
        );

        return response()->json(['usageLogs' => $usageLogs]);
    }

    public function store(StoreResourceUsageLogRequest $request)
    {
        $log = $this->resourceService->startSession(
            $request->validated(),
            $request->user()->librarian->librarianID
        );

        return response()->json([
            'message'  => 'Session started.',
            'usageLog' => $log,
        ], 201);
    }

    public function end(ResourceUsageLog $usageLog)
    {
        $usageLog = $this->resourceService->endSession($usageLog);

        return response()->json([
            'message'  => 'Session ended.',
            'usageLog' => $usageLog,
        ]);
    }
}
