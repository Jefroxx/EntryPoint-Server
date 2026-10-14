<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Models\Librarian;
use App\Services\AdminAccountService;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(private AdminAccountService $accounts)
    {
    }

    public function index(Request $request)
    {
        return response()->json(['staff' => $this->accounts->staff($request->query('search'), $request->user())]);
    }

    public function store(StoreStaffRequest $request)
    {
        return response()->json($this->accounts->createStaff($request->user(), $request->validated()), 201);
    }

    public function update(UpdateStaffRequest $request, Librarian $librarian)
    {
        return response()->json(['account' => $this->accounts->updateStaff($request->user(), $librarian, $request->validated())]);
    }

    public function disable(Request $request, Librarian $librarian)
    {
        $this->accounts->setDisabled($request->user(), $librarian->user, true);

        return response()->json(['message' => 'Account disabled.']);
    }

    public function enable(Request $request, Librarian $librarian)
    {
        $this->accounts->setDisabled($request->user(), $librarian->user, false);

        return response()->json(['message' => 'Account enabled.']);
    }

    public function resetPassword(Request $request, Librarian $librarian)
    {
        return response()->json(['temporaryPassword' => $this->accounts->resetPassword($request->user(), $librarian->user)]);
    }
}
