<?php

namespace App\Http\Controllers;

use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $resets)
    {
    }

    /** "Forgot password?": always the same answer, so it can't be used to find out which emails have accounts. */
    public function forgot(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $this->resets->sendLink($validated['email']);

        return response()->json([
            'message' => 'If that email has an account, a link to choose a new password is on its way.',
        ]);
    }

    /** Asked when the page opens, so a link that was already used or has expired is refused before anything is typed. */
    public function check(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
        ]);

        return response()->json(['usable' => $this->resets->linkIsUsable($validated['email'], $validated['token'])]);
    }

    /** The page the emailed link opens: the new password, with the token and address from the link. */
    public function reset(Request $request)
    {
        $validated = $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'token'    => ['required', 'string'],
            // The same minimum as registering and changing a password on the student side.
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        if (! $this->resets->reset($validated['email'], $validated['token'], $validated['password'])) {
            throw ValidationException::withMessages([
                'email' => ['This link has expired or was already used. Go back to sign-in and ask for a new one.'],
            ]);
        }

        return response()->json(['message' => 'Your password has been changed. You can sign in now.']);
    }
}
