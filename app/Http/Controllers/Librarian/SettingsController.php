<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    /** Collections (books.circulationType) that loan periods and fine rules are set for. */
    private const AREAS = ['circulation', 'reserved', 'filipiniana'];

    public function show(Request $request)
    {
        return response()->json([
            'loanPeriods' => $this->loanPeriods(),
            'fineRules'   => collect(self::AREAS)->map(fn ($area) => $this->ruleFor($area))->values(),
            'account'     => $this->account($request),
        ]);
    }

    public function updateLoanPeriods(Request $request)
    {
        $validated = $request->validate([
            'circulation' => ['required', 'integer', 'min:1', 'max:60'],
            'reserved'    => ['required', 'integer', 'min:1', 'max:60'],
            'filipiniana' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        foreach ($validated as $area => $days) {
            Setting::put("due_days.{$area}", $days);
        }

        return response()->json([
            'message'     => 'Loan periods saved. They apply to new checkouts.',
            'loanPeriods' => $this->loanPeriods(),
        ]);
    }

    public function saveFineRule(Request $request, string $area)
    {
        abort_unless(in_array($area, self::AREAS, true), 404);

        $validated = $request->validate([
            'rate'            => ['required', 'numeric', 'min:0', 'max:99999'],
            'rateUnit'        => ['required', Rule::in(['day', 'hour'])],
            'gracePeriodDays' => ['required', 'integer', 'min:0', 'max:60'],
        ]);

        $type = PenaltyType::firstOrCreate(['category' => $area], ['uuid' => (string) Str::uuid()]);
        $rule = $type->rules()->first();

        if ($rule) {
            $rule->update($validated);
        } else {
            $type->rules()->create(['uuid' => (string) Str::uuid()] + $validated);
        }

        return response()->json([
            'message' => 'Fine rule saved.',
            'rule'    => $this->ruleFor($area),
        ]);
    }

    public function deleteFineRule(string $area)
    {
        abort_unless(in_array($area, self::AREAS, true), 404);

        // Existing fines keep their type; only the rate is removed, so new late returns stop accruing.
        PenaltyType::where('category', $area)->first()?->rules()->delete();

        return response()->json([
            'message' => 'Fine rule removed. Late returns in this collection are no longer charged.',
            'rule'    => $this->ruleFor($area),
        ]);
    }

    public function updateAccount(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName'  => ['required', 'string', 'max:100'],
            'email'     => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->userID, 'userID')],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Account updated.',
            'account' => $this->account($request),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['currentPassword'], $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['Your current password is incorrect.'],
            ]);
        }

        $user->update(['password' => Hash::make($validated['newPassword'])]);

        return response()->json(['message' => 'Password changed.']);
    }

    private function loanPeriods(): array
    {
        return collect(self::AREAS)->mapWithKeys(fn ($area) => [$area => Setting::dueDays($area)])->all();
    }

    private function ruleFor(string $area): array
    {
        $rule = Penalty::ruleForArea($area);

        return [
            'area'            => $area,
            'configured'      => (bool) $rule,
            'rate'            => $rule ? (float) $rule->rate : null,
            'rateUnit'        => $rule->rateUnit ?? 'day',
            'gracePeriodDays' => $rule->gracePeriodDays ?? 0,
        ];
    }

    private function account(Request $request): array
    {
        $user = $request->user();

        return [
            'firstName' => $user->firstName,
            'lastName'  => $user->lastName,
            'email'     => $user->email,
        ];
    }
}
