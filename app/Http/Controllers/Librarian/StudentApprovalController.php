<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\SystemNotification;
use App\Models\Student;
use Illuminate\Http\Request;

class StudentApprovalController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search'   => ['nullable', 'string', 'max:255'],
            'program'  => ['nullable', 'string', 'max:255'],
            'status'   => ['nullable', 'string', 'in:pending,approved,rejected'],
            'perPage'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $students = Student::with('user')
            ->when($validated['search'] ?? null, function ($query, $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('studentIDNumber', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('firstName', 'like', "%{$term}%")
                            ->orWhere('lastName', 'like', "%{$term}%"));
                });
            })
            ->when($validated['program'] ?? null, fn ($query, $program) => $query->where('academicProgram', $program))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('registrationStatus', $status))
            ->orderByDesc('studentID')
            ->paginate($validated['perPage'] ?? 15);

        return response()->json($students);
    }

    public function stats()
    {
        $counts = Student::selectRaw('registrationStatus, COUNT(*) as total')
            ->groupBy('registrationStatus')
            ->pluck('total', 'registrationStatus');

        return response()->json([
            'total'    => $counts->sum(),
            'pending'  => $counts->get('pending', 0),
            'approved' => $counts->get('approved', 0),
            'rejected' => $counts->get('rejected', 0),
        ]);
    }

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
