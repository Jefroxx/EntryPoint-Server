<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\StudentRegistrationRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth)
    {
    }

    public function register(StudentRegistrationRequest $request)
    {
        $student = $this->auth->register($request->validated());

        return response()->json([
            'message' => 'Registration submitted. Your account is pending librarian approval.',
            'student' => $student,
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $validated = $request->validated();
        $result = $this->auth->login($validated['email'], $validated['password']);

        return response()->json([
            'user'         => $result['user'],
            'access_token' => $result['token'],
            'token_type'   => 'Bearer',
        ]);
    }

    public function librarianLogin(LoginRequest $request)
    {
        $validated = $request->validated();
        $result = $this->auth->librarianLogin($validated['email'], $validated['password']);

        return response()->json([
            'user'         => $result['user'],
            'access_token' => $result['token'],
            'token_type'   => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $this->auth->logout($request->user());

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
