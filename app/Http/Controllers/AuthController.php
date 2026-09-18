<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRegistrationRequest;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(StudentRegistrationRequest $request)
    {
        $validated = $request->validated();

        $student = DB::transaction(function () use ($validated) {
            $user = User::create([
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

            return Student::create([
                'studentID'          => $user->userID,
                'uuid'               => Str::uuid(),
                'studentIDNumber'    => $validated['studentIDNumber'],
                'barcodeValue'       => null,
                'academicProgram'    => $validated['academicProgram'] ?? null,
                'registrationStatus' => 'pending',
            ]);
        });

        Librarian::all()->each(function ($librarian) use ($validated) {
            SystemNotification::notify(
                $librarian->librarianID,
                "New student registration pending approval: {$validated['firstName']} {$validated['lastName']}",
                'new_registration'
            );
        });

        return response()->json([
            'message' => 'Registration submitted. Your account is pending librarian approval.',
            'student' => $student->load('user'),
        ], 201);
    }

    /** Student portal sign-in. Librarian accounts are rejected here. */
    public function login(Request $request)
    {
        return $this->attemptLogin($request, 'student');
    }

    /** Librarian portal sign-in. Student accounts are rejected here. */
    public function librarianLogin(Request $request)
    {
        return $this->attemptLogin($request, 'librarian');
    }

    private function attemptLogin(Request $request, string $portal)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        // Signing in on the wrong portal fails exactly like a bad password, so
        // neither endpoint reveals which emails exist or what type they are.
        if (! $user || ! Hash::check($request->password, $user->password) || $user->userType !== $portal) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($portal === 'student') {
            $student = $user->student;

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
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user'         => $user->load($user->userType === 'student' ? 'student' : 'librarian'),
            'access_token' => $token,
            'token_type'   => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
