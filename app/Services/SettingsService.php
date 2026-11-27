<?php

namespace App\Services;

use App\Models\LoanPeriod;
use App\Models\User;
use App\Repositories\Contracts\LoanPeriodRepositoryInterface;
use App\Repositories\Contracts\PenaltyRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingsService
{
    // A fine rule can be set for any area of the library (Library > Areas & Funds). How each area is lent out, and its
    // loan period, is set with the area itself.
    public function __construct(
        private LoanPeriodRepositoryInterface $loanPeriods,
        private PenaltyRepositoryInterface $penalties,
        private UserRepositoryInterface $users,
        private ReservationService $reservations,
    ) {
    }

    public function overview(User $account): array
    {
        $fineRules = [];

        // Every area, whether or not it has a rule yet: the page lists the ones that do and offers the rest in "Add".
        foreach (LoanPeriod::orderBy('sortOrder')->orderBy('name')->get() as $area) {
            $rule = $this->penalties->fineRuleForArea($area->area);
            $fineRules[] = [
                'area'            => $area->area,
                'areaName'        => $area->name,
                'loanable'        => $area->loanable,
                'configured'      => (bool) $rule,
                'rate'            => $rule ? (float) $rule->rate : null,
                'rateUnit'        => $rule->rateUnit ?? 'day',
                'gracePeriodDays' => $rule->gracePeriodDays ?? 0,
            ];
        }

        return [
            'reservationHoldHours' => AppSettings::holdHours(),
            'maxBooks'            => AppSettings::maxBooks(),
            'lowStockAt'          => AppSettings::lowStockAt(),
            'fineRules'   => $fineRules,
            'account'     => $this->account($account),
        ];
    }

    /**
     * The "Your account" screen (librarian Settings and the admin side show the same one): what can be edited, and
     * the record the system keeps (role, username, phone), shown read-only. Also GET /librarian/settings/account, for
     * the admin side, which can't open the rest of the librarian settings.
     */
    public function account(User $account): array
    {
        $role = $account->librarian?->role ?? 'librarian';

        return [
            'firstName'   => $account->firstName,
            'lastName'    => $account->lastName,
            'email'       => $account->email,
            'username'    => $account->username,
            'phoneNumber' => $account->phoneNumber,
            'role'        => $role,
            'roleLabel'   => AdminAccountService::ROLE_LABELS[$role] ?? 'Librarian',
        ];
    }

    /** How long an accepted reservation holds a copy, and the most books a student can have at a time. */
    public function saveBorrowing(int $holdHours, int $maxBooks): array
    {
        AppSettings::set(AppSettings::RESERVATION_HOLD_HOURS, $holdHours);
        AppSettings::set(AppSettings::MAX_BOOKS, $maxBooks);

        // A lower limit takes effect for students who are already over it (see ReservationService::trimToLimit).
        return $this->reservations->trimToLimit($maxBooks);
    }

    /** How many students and reservations a new limit would affect, without changing anything. */
    public function limitImpact(int $maxBooks): array
    {
        return $this->reservations->trimToLimit($maxBooks, apply: false);
    }

    public function saveRewards(int $lowStockAt): void
    {
        AppSettings::set(AppSettings::LOW_STOCK_AT, $lowStockAt);
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
        // Changing the email changes where password resets go, so it needs the password: a borrowed, signed-in session
        // can't quietly point the account at someone else's inbox.
        if (isset($payload['email']) && strcasecmp($payload['email'], (string) $user->email) !== 0) {
            if (! Hash::check((string) ($payload['currentPassword'] ?? ''), $user->password)) {
                throw ValidationException::withMessages([
                    'currentPassword' => ['Enter your current password to change your email.'],
                ]);
            }
        }

        $this->users->update($user, array_filter([
            'firstName' => $payload['firstName'],
            'lastName'  => $payload['lastName'],
            'email'     => $payload['email'] ?? null,
        ], fn ($value) => $value !== null));

        $user = $user->fresh();

        return [
            'firstName' => $user->firstName,
            'lastName'  => $user->lastName,
            'email'     => $user->email,
        ];
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword, ?int $keepTokenID = null): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['Current password is incorrect.'],
            ]);
        }

        // Someone on a temporary password has to pick a different one, or the administrator would still know it.
        if ($user->mustChangePassword && Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'newPassword' => ['Choose a password different from the temporary one.'],
            ]);
        }

        $this->users->update($user, ['password' => Hash::make($newPassword), 'mustChangePassword' => false]);

        // Every other signed-in device has to sign in again with the new password; this one stays.
        $user->tokens()->when($keepTokenID, fn ($q) => $q->where('id', '!=', $keepTokenID))->delete();
    }
}
