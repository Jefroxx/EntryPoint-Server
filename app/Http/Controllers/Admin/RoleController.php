<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRolePermissionsRequest;
use App\Services\AdminAccountService;

class RoleController extends Controller
{
    public function __construct(private AdminAccountService $accounts)
    {
    }

    public function index()
    {
        return response()->json($this->accounts->roles());
    }

    public function update(SaveRolePermissionsRequest $request, string $role)
    {
        abort_unless(in_array($role, ['librarian', 'assistant'], true), 404);

        return response()->json($this->accounts->saveRole($request->user(), $role, $request->validated('permissions')));
    }
}
