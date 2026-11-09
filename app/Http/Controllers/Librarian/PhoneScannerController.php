<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Services\PhoneScannerService;
use Illuminate\Http\Request;

/** The desk's side of "use my phone as the camera": get a code for the QR, read what the phone scanned, disconnect. */
class PhoneScannerController extends Controller
{
    public function __construct(private PhoneScannerService $phones)
    {
    }

    public function pair(Request $request)
    {
        // `portalUrl` is the address people reach this deployment on (PORTAL_URL, the real domain when hosted). The code
        // only works against the server that made it, so this is the address the phone has to open.
        return response()->json($this->phones->createPairing($request->user()) + ['portalUrl' => config('portal.url')]);
    }

    public function scans(Request $request)
    {
        $data = $request->validate(['as' => ['nullable', 'in:desk,station']]);

        return response()->json($this->phones->take($request->user(), $data['as'] ?? 'desk'));
    }

    public function disconnect(Request $request)
    {
        $this->phones->disconnect($request->user());

        return response()->json(['message' => 'Phones disconnected.']);
    }
}
