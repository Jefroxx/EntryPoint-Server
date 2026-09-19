<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\LoanPeriodRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingsService
{
    // The only areas the client's Settings page edits — the other
    // areasOfLibrary values (fiction, thesis, journal, dissertation) keep
    // whatever loan_periods row the classification migration seeded.
    private const COLLECTION_AREAS = ['circulation', 'reserved', 'filipiniana'];

    public function __construct(
        private LoanPeriodRepositoryInterface $loanPeriods,
        private PenaltyRepositoryInterface $penalties,
        private UserRepositoryInterface $users,
    ) {
    }

    public function overview(User $account): array
    {
        $periods = $this->loanPeriods->all()->keyBy('area');

        $loanPeriods = [];
        $fineRules = [];

        foreach (self::COLLECTION_AREAS as $area) {
            $period = $periods->get($area);
            $loanPeriods[$area] = $period?->periodValue ?? 0;

            $rule = $this->penalties->fineRuleForArea($area);
            $fineRules[] = [
                'area'            => $area,
                'configured'      => (bool) $rule,
                'rate'            => $rule ? (float) $rule->rate : null,
                'rateUnit'        => $rule->rateUnit ?? 'day',
                'gracePeriodDays' => $rule->gracePeriodDays ?? 0,
            ];
        }

        return [
            'loanPeriods' => $loanPeriods,
            'fineRules'   => $fineRules,
            'account'     => [
                'firstName' => $account->firstName,
                'lastName'  => $account->lastName,
                'email'     => $account->email,
            ],
        ];
    }

    public function saveLoanPeriods(array $payload): void
    {
        foreach ($payload as $area => $days) {
            if (! in_array($area, self::COLLECTION_AREAS, true)) {
                continue;
            }

            $this->loanPeriods->upsert($area, (int) $days, 'days');
        }
    }

    public function saveFineRule(string $area, array $payload): array
    {
        $rule = $this->penalties->upsertFineRule(
            $area,
            (float) $payload['rate'],
            $payload['rateUnit'],
            (int) $payload['gracePeriodDays']
        );

        return [
            'area'            => $area,
            'configured'      => true,
            'rate'            => (float) $rule->rate,
            'rateUnit'        => $rule->rateUnit,
            'gracePeriodDays' => $rule->gracePeriodDays,
        ];
    }

    public function deleteFineRule(string $area): void
    {
        $this->penalties->deleteFineRule($area);
    }

    /**
     * @return array{firstName: string, lastName: string, email: string}
     */
    public function updateAccount(User $user, array $payload): array
    {
        $this->users->update($user, [
            'firstName' => $payload['firstName'],
            'lastName'  => $payload['lastName'],
            'email'     => $payload['email'],
        ]);

        $user = $user->fresh();

        return [
            'firstName' => $user->firstName,
            'lastName'  => $user->lastName,
            'email'     => $user->email,
        ];
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['Current password is incorrect.'],
            ]);
        }

        $this->users->update($user, ['password' => Hash::make($newPassword)]);
    }
}
