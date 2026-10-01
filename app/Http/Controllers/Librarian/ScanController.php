<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Services\ScanService;
use Illuminate\Http\Request;

/** The universal scanner: one endpoint, and the code decides what happens (see ScanService). */
class ScanController extends Controller
{
    public function __construct(private ScanService $scanner)
    {
    }

    public function store(Request $request)
    {
        // Scanners often append a line break or tab to the code; strip them before validating.
        $request->merge(['code' => preg_replace('/[\r\n\t]+/', '', trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code'  => ['required', 'string', 'max:64'],
            'resID' => ['nullable', 'integer'],
        ]);

        return response()->json($this->scanner->handle(
            $data['code'],
            isset($data['resID']) ? (int) $data['resID'] : null,
            $request->user()->librarian->librarianID,
        ));
    }
}
