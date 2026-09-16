<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    /**
     * Real-time availability list — students can browse this to plan a
     * visit but cannot start/end sessions themselves (librarian-mediated).
     */
    public function index(Request $request)
    {
        $query = Resource::query();

        if ($request->filled('resourceType')) {
            $query->where('resourceType', $request->query('resourceType'));
        }

        return response()->json([
            'resources' => $query->orderBy('name')->get(['resID', 'resourceType', 'name', 'status']),
        ]);
    }
}
