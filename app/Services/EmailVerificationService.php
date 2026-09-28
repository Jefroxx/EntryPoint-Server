<?php

namespace App\Services;

use App\Mail\VerifyStudentEmail;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Proves a registering student owns the email they typed: a signed, expiring link is mailed to it,
 * and clicking the link marks the address confirmed. Only then do librarians hear about the
 * registration, so the approval queue never fills with typos or someone else's address.
 */
class EmailVerificationService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private NotificationService $notifications,
    ) {
    }

    /** Returns false if the mail couldn't be sent; the student can ask for another from the sign-in page. */
    public function sendLink(User $user): bool
    {
        try {
            Mail::to($user->email)->send(new VerifyStudentEmail($user, $this->linkFor($user)));

            return true;
        } catch (Throwable $exception) {
            Log::error("Verification email failed for user {$user->userID}", ['exception' => $exception]);

            return false;
        }
    }

    /**
     * Sends a fresh link if the address belongs to a student still waiting to confirm. Silent otherwise,
     * so the endpoint can't be used to find out which emails have accounts.
     */
    public function resend(string $email): void
    {
        $user = $this->users->findByEmail($email);

        if ($user && $user->userType === 'student' && ! $user->emailVerifiedAt) {
            $this->sendLink($user);
        }
    }

    /**
     * Called with the pieces of a link whose signature the controller already checked.
     *
     * @return 'verified'|'already'|'invalid'
     */
    public function verify(string $uuid, string $hash): string
    {
        $user = $this->users->findByUuid($uuid);

        // The hash ties the link to the address it was sent to; a later email change voids old links.
        if (! $user || ! hash_equals($this->hashFor($user), $hash)) {
            return 'invalid';
        }

        if ($user->emailVerifiedAt) {
            return 'already';
        }

        $this->users->update($user, ['emailVerifiedAt' => now()]);

        $this->notifications->notifyAllLibrarians(
            "New student registration pending approval: {$user->firstName} {$user->lastName}",
            'new_registration'
        );

        return 'verified';
    }

    private function linkFor(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('portal.verify_link_minutes')),
            ['uuid' => $user->uuid, 'hash' => $this->hashFor($user)],
        );
    }

    private function hashFor(User $user): string
    {
        return sha1(strtolower($user->email));
    }
}
