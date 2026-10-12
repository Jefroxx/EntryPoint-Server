<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Services\StaffPermissionService;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(private StaffPermissionService $permissions)
    {
    }

    /** Who is signed in on the staff side and what their role allows, so the screens can show only what applies. */
    public function show(Request $request)
    {
        return response()->json($this->permissions->describe($request->user()));
    }
}
