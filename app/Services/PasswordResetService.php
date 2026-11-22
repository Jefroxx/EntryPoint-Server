<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * "Forgot password?" for the student side (students, and the faculty and staff members who borrow like them).
 *
 * It uses Laravel's own password reset, so the link works once, expires after `auth.passwords.users.expire` minutes
 * (60), is stored hashed, and asking again replaces the earlier one. Two rules on top of that:
 *
 *   - Nothing it says depends on whether an account exists, so it can't be used to find out who is registered.
 *   - Only a switched-on student-side account can use it. Library staff have an administrator who resets their
 *     password (Admin > Staff), and a disabled account stays disabled.
 */
class PasswordResetService
{
    public function __construct(private LoginThrottle $throttle, private AuditLogService $audit)
    {
    }

    /** Which accounts may use a reset link: the broker looks the person up with these. */
    private function eligible(string $email): array
    {
        return ['email' => $email, 'userType' => 'student', 'disabledAt' => null];
    }

    /** Mails a link when the address belongs to an account that may use one. Silent otherwise, and when the mail fails. */
    public function sendLink(string $email): void
    {
        try {
            Password::sendResetLink($this->eligible($email));
        } catch (Throwable $exception) {
            // The person can ask again; there is nothing useful to tell them that would not give accounts away.
            Log::error('Password reset email failed', ['exception' => $exception]);
        }
    }

    /** Whether a link can still be used: real, unused, unexpired, and for an account that may reset. Changes nothing. */
    public function linkIsUsable(string $email, string $token): bool
    {
        $user = Password::getUser($this->eligible($email));

        return $user !== null && Password::tokenExists($user, $token);
    }

    /** Sets the new password if the link is real, unused and unexpired. */
    public function reset(string $email, string $token, string $password): bool
    {
        $status = Password::reset(
            $this->eligible($email) + ['token' => $token, 'password' => $password, 'password_confirmation' => $password],
            function (User $user, string $newPassword) {
                $user->forceFill(['password' => Hash::make($newPassword), 'mustChangePassword' => false])->save();

                // Whoever knew the old password, or held a sign-in made with it, is out; and a lock from too many wrong
                // guesses is lifted, so the person can sign in straight away.
                $user->tokens()->delete();
                $this->throttle->clear($user->email);

                $this->audit->record(null, 'security.password_reset', mb_substr("Password reset from the emailed link: \"{$user->email}\"", 0, 255));
            },
        );

        return $status === Password::PASSWORD_RESET;
    }
}
