<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\SaveFineRuleRequest;
use App\Http\Requests\SaveLoanPeriodsRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->settings->overview($request->user()));
    }

    public function saveLoanPeriods(SaveLoanPeriodsRequest $request)
    {
        $this->settings->saveLoanPeriods($request->validated());

        return response()->json(['message' => 'Loan periods updated.']);
    }

    public function saveFineRule(SaveFineRuleRequest $request, string $area)
    {
        $this->settings->saveFineRule($area, $request->validated());

        return response()->json(['message' => 'Fine rule updated.']);
    }

    public function deleteFineRule(string $area)
    {
        $this->settings->deleteFineRule($area);

        return response()->json(['message' => 'Fine rule removed.']);
    }

    public function updateAccount(UpdateAccountRequest $request)
    {
        $account = $this->settings->updateAccount($request->user(), $request->validated());

        return response()->json([
            'message' => 'Account updated.',
            'account' => $account,
        ]);
    }

    public function changePassword(ChangePasswordRequest $request)
    {
        $validated = $request->validated();

        $this->settings->changePassword($request->user(), $validated['currentPassword'], $validated['newPassword']);

        return response()->json(['message' => 'Password changed.']);
    }
}
