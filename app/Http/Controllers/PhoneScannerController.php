<?php

namespace App\Http\Controllers;

use App\Services\PhoneScannerService;
use Illuminate\Http\Request;

/**
 * The phone's side. `claim` is public (the one-time code is the proof); the rest need the phone's limited token, which
 * is allowed these routes and nothing else (see RejectScopedTokens).
 */
class PhoneScannerController extends Controller
{
    public function __construct(private PhoneScannerService $phones)
    {
    }

    public function claim(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);

        return response()->json($this->phones->claim($data['code']));
    }

    public function ping(Request $request)
    {
        $user = $this->staffOrFail($request);
        $this->phones->touch($user);

        return response()->json(['name' => $user->fullName]);
    }

    public function scan(Request $request)
    {
        $user = $this->staffOrFail($request);
        $data = $request->validate(['code' => ['required', 'string', 'min:1', 'max:200']]);

        $this->phones->push($user, trim($data['code']));

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Disconnected.']);
    }

    /** The phone's account must still be an active librarian (it could have been disabled since the QR was scanned). */
    private function staffOrFail(Request $request)
    {
        $user = $request->user();

        if ($user->userType !== 'librarian' || $user->disabledAt) {
            $user->tokens()->where('name', PhoneScannerService::TOKEN_NAME)->delete();
            abort(403, 'This account can no longer scan.');
        }

        return $user;
    }
}
