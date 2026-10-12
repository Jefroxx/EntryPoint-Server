<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private AuditLogService $audit)
    {
    }

    public function index(Request $request)
    {
        $page = $this->audit->index(
            $request->query('search'),
            $request->query('area'),
            $request->query('actor') ? (int) $request->query('actor') : null,
            (int) ($request->query('perPage') ?? 20),
        );

        return response()->json([
            'areas' => $this->audit->areas(),
            'logs'  => $page,
        ]);
    }
}
