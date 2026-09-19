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
            (int) ($request->query('perPage') ?? 15)
        ));
    }

    public function stats()
    {
        return response()->json($this->studentApproval->stats());
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
