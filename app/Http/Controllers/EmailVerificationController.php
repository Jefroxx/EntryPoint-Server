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
        if (! URL::hasCorrectSignature($request, absolute: false)) {
            $result = 'invalid';
        } elseif (! URL::signatureHasNotExpired($request)) {
            $result = 'expired';
        } else {
            $status = $this->verification->verify($uuid, $hash);
            $result = $status === 'verified' ? '1' : $status;
        }

        // Staff and faculty accounts an administrator made have no approval after this, so they get their own
        // message ("staff" or "member"), and staff land on the librarian sign-in.
        $kind = $this->verification->kindOf($uuid);
        if ($kind !== 'student' && in_array($result, ['1', 'already'], true)) {
            $result = $kind;
        }

        // A relative address: the browser resolves it against the site the link was opened on, so the student
        // lands on the right domain without Laravel having to know it.
        return redirect()->away(($kind === 'staff' ? '/librarian/login' : '/login') . '?verified=' . $result);
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
