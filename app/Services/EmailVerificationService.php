<?php

namespace App\Services;

use App\Mail\VerifyStudentEmail;
use App\Models\User;
use App\Support\PortalUrl;
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
            Mail::to($user->email)->send(new VerifyStudentEmail($user, $this->linkFor($user), $this->madeByAdmin($user)));

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

        // Students confirming their registration, and staff or faculty accounts an administrator made.
        if ($user && $user->email && in_array($user->userType, ['student', 'librarian'], true) && ! $user->emailVerifiedAt) {
            $this->sendLink($user);
        }
    }

    /**
     * An administrator made this account (staff, faculty or staff member), so confirming the email is all that is
     * left. A student who registered herself still has a librarian's approval ahead of her.
     */
    public function madeByAdmin(User $user): bool
    {
        return $user->userType === 'librarian' || $user->student?->registrationStatus === 'approved';
    }

    /** Which sign-in page the link should end on: 'student' (self-registered), 'member' (faculty/staff) or 'staff'. */
    public function kindOf(string $uuid): string
    {
        $user = $this->users->findByUuid($uuid);

        if (! $user) {
            return 'student';
        }

        if ($user->userType === 'librarian') {
            return 'staff';
        }

        return $this->madeByAdmin($user) ? 'member' : 'student';
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

        // Only a student who registered herself waits for approval; an account an administrator made does not.
        if (! $this->madeByAdmin($user)) {
            $this->notifications->notifyAllLibrarians(
                "New student registration pending approval: {$user->firstName} {$user->lastName}",
                'new_registration'
            );
        }

        return 'verified';
    }

    private function linkFor(User $user): string
    {
        // The signature covers the path only (absolute: false), so it stays valid whatever host the request
        // reaches Laravel on (behind the Nuxt proxy, a tunnel or the real domain). The address in front of
        // it is the site the student is on, via /api, which the web server forwards to Laravel.
        return PortalUrl::current() . URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config('portal.verify_link_minutes')),
            ['uuid' => $user->uuid, 'hash' => $this->hashFor($user)],
            absolute: false,
        );
    }

    private function hashFor(User $user): string
    {
        return sha1(strtolower($user->email));
    }
}
