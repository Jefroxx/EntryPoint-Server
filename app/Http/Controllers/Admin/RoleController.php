<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRolePermissionsRequest;
use App\Http\Requests\Admin\SaveRolesRequest;
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

    /** Every changed role at once: the roles screen's single Save button. */
    public function updateAll(SaveRolesRequest $request)
    {
        return response()->json($this->accounts->saveRoles($request->user(), $request->validated('roles')));
    }

    public function update(SaveRolePermissionsRequest $request, string $role)
    {
        abort_unless(in_array($role, ['librarian', 'assistant'], true), 404);

        return response()->json($this->accounts->saveRole($request->user(), $role, $request->validated('permissions')));
    }
}
