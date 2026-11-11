<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\StudentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /** Cache key for a sign-in that was ended because the account signed in somewhere else (see bootstrap/app.php). */
    public const REPLACED_TOKEN_PREFIX = 'token-replaced:';

    public function __construct(
        private UserRepositoryInterface $users,
        private StudentRepositoryInterface $students,
        private EmailVerificationService $verification,
        private LoginThrottle $throttle,
    ) {
    }

    public function register(array $validated): Student
    {
        $student = DB::transaction(function () use ($validated) {
            $user = $this->users->create([
                'uuid'          => Str::uuid(),
                'firstName'     => $validated['firstName'],
                'middleInitial' => $validated['middleInitial'] ?? null,
                'lastName'      => $validated['lastName'],
                'email'         => $validated['email'],
                'password'      => Hash::make($validated['password']),
                'phoneNumber'   => $validated['phoneNumber'] ?? null,
                'birthDate'     => $validated['birthDate'] ?? null,
                'address'       => $validated['address'] ?? null,
                'userType'      => 'student',
            ]);

            return $this->students->create([
                'studentID'          => $user->userID,
                'uuid'               => Str::uuid(),
                'memberIDNumber'    => $validated['memberIDNumber'],
                'barcodeValue'       => null,
                'academicProgram'    => $validated['academicProgram'] ?? null,
                'registrationStatus' => 'pending',
            ]);
        });

        // Librarians are told once the student clicks the link (EmailVerificationService::verify), not now.
        $this->verification->sendLink($student->user);

        return $student->load('user');
    }

    /**
     * Student portal sign-in — rejects librarian accounts.
     *
     * @return array{user: User, token: string}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->authenticate($email, $password);

        if ($user->userType !== 'student') {
            throw ValidationException::withMessages([
                'email' => ['Use the librarian portal to sign in with this account.'],
            ]);
        }

        $student = $user->student;

        // Checked before approval status, since an unconfirmed registration hasn't reached a librarian yet.
        // `verification` is a flag the sign-in page reads to offer "Send a new link".
        if (! $user->emailVerifiedAt) {
            throw ValidationException::withMessages([
                'email'        => ["Confirm your email first. We sent a link to {$user->email}; check your inbox and Junk folder."],
                'verification' => ['required'],
            ]);
        }

        if (! $student || $student->registrationStatus !== 'approved') {
            $status = $student->registrationStatus ?? 'pending';

            throw ValidationException::withMessages([
                'email' => match ($status) {
                    'pending'  => ['Your account is still pending librarian approval.'],
                    'rejected' => ['Your registration was rejected. Please contact the library.'],
                    default    => ['Your account cannot log in at this time.'],
                },
            ]);
        }

        return $this->issueToken($user, 'student');
    }

    /**
     * Librarian portal sign-in — rejects student accounts.
     *
     * @return array{user: User, token: string}
     */
    public function librarianLogin(string $email, string $password): array
    {
        $user = $this->authenticate($email, $password);

        if ($user->userType !== 'librarian') {
            throw ValidationException::withMessages([
                'email' => ['Use the student portal to sign in with this account.'],
            ]);
        }

        // A staff account an administrator made with an email must confirm it first. (A student assistant's
        // username-only account has no email to confirm.)
        if ($user->email && ! $user->emailVerifiedAt) {
            throw ValidationException::withMessages([
                'email'        => ["Confirm your email first. We sent a link to {$user->email}; check your inbox and Junk folder."],
                'verification' => ['required'],
            ]);
        }

        return $this->issueToken($user, 'librarian');
    }

    private function authenticate(string $email, string $password): User
    {
        // Repeated failures lock the sign-in for a few minutes (see LoginThrottle); checked first, so a locked-out
        // guesser doesn't even get an answer about the password.
        $this->throttle->ensureNotLocked($email);

        $user = $this->users->findByLogin($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->throttle->fail($email);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $this->throttle->clear($email);

        if ($user->isDisabled()) {
            throw ValidationException::withMessages([
                'email' => ['This account has been disabled. Contact the library administrator.'],
            ]);
        }

        return $user;
    }

    /**
     * @return array{user: User, token: string}
     */
    private function issueToken(User $user, string $relation): array
    {
        // A student account is open in one place at a time: signing in again (another browser, another device) signs
        // the earlier one out. Their ids are remembered for a day, so that earlier session can be told why it ended.
        // Librarians are not limited this way: they sign in on a phone to scan while the desk stays signed in.
        if ($relation === 'student') {
            foreach ($user->tokens()->pluck('id') as $id) {
                Cache::put(self::REPLACED_TOKEN_PREFIX.$id, true, now()->addDay());
            }
            $user->tokens()->delete();
        }

        // How long a sign-in lasts before the password is needed again. Staff accounts can change records, fines and
        // other people's accounts, so theirs are shorter than a student's (config sanctum.expiration, 14 days); an
        // administrator's is shorter still. A token that is stolen or forgotten on a shared desk stops working by itself.
        $minutes = match (true) {
            $relation === 'student'                                  => null,
            $user->librarian?->role === 'admin'                      => 60 * 12,
            default                                                  => 60 * 24 * 3,
        };

        $token = $user->createToken('auth_token', ['*'], $minutes ? now()->addMinutes($minutes) : null)->plainTextToken;

        return [
            'user'  => $user->load($relation),
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();

        // A librarian who signs out of the desk takes their paired phones with them.
        if ($user->userType === 'librarian') {
            app(PhoneScannerService::class)->disconnect($user);
        }
    }
}
