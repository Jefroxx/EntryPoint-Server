<?php

namespace App\Http\Controllers;

use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    public function __construct(private EmailVerificationService $verification)
    {
    }

    /**
     * The link in the email. Opened in a browser, not by the app, so it answers with a redirect to the
     * sign-in page (?verified=1 | already | expired | invalid) rather than JSON.
     */
    public function verify(Request $request, string $uuid, string $hash)
    {
        // Checked here instead of with the `signed` middleware, whose 403 page would strand the student.
        if (! URL::hasCorrectSignature($request)) {
            $result = 'invalid';
        } elseif (! URL::signatureHasNotExpired($request)) {
            $result = 'expired';
        } else {
            $status = $this->verification->verify($uuid, $hash);
            $result = $status === 'verified' ? '1' : $status;
        }

        return redirect()->away(config('portal.url') . '/login?verified=' . $result);
    }

    public function resend(Request $request)
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $this->verification->resend($validated['email']);

        // Same answer whether or not the address has an account.
        return response()->json([
            'message' => 'If that email is waiting to be confirmed, a new link is on its way.',
        ]);
    }
}
