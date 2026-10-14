<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\PrivacyService;
use Illuminate\Http\Request;

/** Data privacy tools: find a member, give them a copy of their data, or anonymize their account on request. */
class PrivacyController extends Controller
{
    public function __construct(private PrivacyService $privacy)
    {
    }

    public function search(Request $request)
    {
        return response()->json(['members' => $this->privacy->search((string) $request->query('q'))]);
    }

    public function export(Request $request, Student $student)
    {
        return response()->json($this->privacy->export($request->user(), $student));
    }

    public function anonymize(Request $request, Student $student)
    {
        $this->privacy->anonymize($request->user(), $student);

        return response()->json(['message' => 'The account has been anonymized.']);
    }
}
