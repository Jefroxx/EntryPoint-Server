<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\StudentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private StudentRepositoryInterface $students,
        private EmailVerificationService $verification,
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
                'studentIDNumber'    => $validated['studentIDNumber'],
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

        return $this->issueToken($user, 'librarian');
    }

    private function authenticate(string $email, string $password): User
    {
        $user = $this->users->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $user;
    }

    /**
     * @return array{user: User, token: string}
     */
    private function issueToken(User $user, string $relation): array
    {
        $token = $user->createToken('auth_token')->plainTextToken;

        return [
            'user'  => $user->load($relation),
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
