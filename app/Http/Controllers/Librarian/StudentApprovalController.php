<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\SystemNotification;
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
            'registrationStatus'    => 'approved',
            'barcodeValue'          => Student::generateUniqueBarcode(),
            'reviewedByLibrarianID' => $request->user()->librarian->librarianID,
            'reviewedAt'            => now(),
        ]);

        SystemNotification::notify(
            $student->studentID,
            'Your registration has been approved! You can now log in.',
            'registration_approved'
        );

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

        SystemNotification::notify(
            $student->studentID,
            'Your registration was not approved. Please contact the library for details.',
            'registration_rejected'
        );

        return response()->json([
            'message' => 'Student rejected.',
            'student' => $student->fresh(),
        ]);
    }
}
