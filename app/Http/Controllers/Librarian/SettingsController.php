<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\SaveFineRuleRequest;
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

    public function saveBorrowing(Request $request)
    {
        $data = $request->validate([
            // Up to 30 days, in hours.
            'holdHours' => ['required', 'integer', 'min:1', 'max:720'],
            'maxBooks'  => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $this->settings->saveBorrowing((int) $data['holdHours'], (int) $data['maxBooks']);

        return response()->json(['message' => 'Borrowing settings updated.']);
    }

    public function saveRewards(Request $request)
    {
        $data = $request->validate(['lowStockAt' => ['required', 'integer', 'min:0', 'max:100']]);

        $this->settings->saveRewards((int) $data['lowStockAt']);

        return response()->json(['message' => 'Rewards settings updated.']);
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
