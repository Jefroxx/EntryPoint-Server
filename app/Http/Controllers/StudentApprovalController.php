<?php

namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;

class StudentApprovalController extends Controller
{
    public function approve(Request $request, Student $student)
    {
        if ($student->registrationStatus === 'approved') {
            return response()->json(['message' => 'Student is already approved.'], 422);
        }

        $student->update([
            'registrationStatus'     => 'approved',
            'barcodeValue'           => Student::generateUniqueBarcode(),
            'reviewedByLibrarianID'  => $request->user()->librarian->librarianID,
            'reviewedAt'             => now(),
        ]);

        return response()->json([
            'message' => 'Student approved.',
            'student' => $student->fresh(),
        ]);
    }

    public function reject(Request $request, Student $student)
    {
        if ($student->registrationStatus === 'approved') {
            return response()->json(['message' => 'Cannot reject an already-approved student.'], 422);
        }

        $student->update([
            'registrationStatus'    => 'rejected',
            'reviewedByLibrarianID' => $request->user()->librarian->librarianID,
            'reviewedAt'            => now(),
        ]);

        return response()->json([
            'message' => 'Student rejected.',
            'student' => $student->fresh(),
        ]);
    }
}
