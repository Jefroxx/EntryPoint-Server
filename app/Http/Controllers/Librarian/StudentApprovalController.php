<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\StudentApprovalService;
use Illuminate\Http\Request;

class StudentApprovalController extends Controller
{
    public function __construct(private StudentApprovalService $studentApproval)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->studentApproval->index(
            $request->query('search'),
            $request->query('program'),
            $request->query('status'),
            (int) ($request->query('perPage') ?? 15),
            $this->memberType($request),
        ));
    }

    public function stats(Request $request)
    {
        return response()->json($this->studentApproval->stats($this->memberType($request)));
    }

    /**
     * The Students page asks for students only (?memberType=student). A lookup (checkout, starting a session) leaves it
     * out and finds faculty and staff members too, since they borrow and visit as well.
     */
    private function memberType(Request $request): ?string
    {
        $type = $request->query('memberType');

        return in_array($type, Student::MEMBER_TYPES, true) ? $type : null;
    }

    public function approve(Request $request, Student $student)
    {
        $student = $this->studentApproval->approve($student, $request->user()->librarian->librarianID);

        return response()->json([
            'message' => 'Student approved.',
            'student' => $student,
        ]);
    }

    public function reject(Request $request, Student $student)
    {
        $student = $this->studentApproval->reject($student, $request->user()->librarian->librarianID);

        return response()->json([
            'message' => 'Student rejected.',
            'student' => $student,
        ]);
    }
}
