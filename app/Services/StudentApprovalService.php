<?php

namespace App\Services;

use App\Models\Student;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class StudentApprovalService
{
    public function __construct(
        private StudentRepositoryInterface $students,
        private NotificationService $notifications,
    ) {
    }

    public function index(?string $search, ?string $program, ?string $status, int $perPage): LengthAwarePaginator
    {
        return $this->students->paginate($search, $program, $status, $perPage);
    }

    /**
     * @return array{total: int, pending: int, approved: int, rejected: int}
     */
    public function stats(): array
    {
        return $this->students->countsByRegistrationStatus();
    }

    public function approve(Student $student, int $librarianID): Student
    {
        if ($student->registrationStatus === 'approved') {
            throw ValidationException::withMessages(['student' => ['Student is already approved.']]);
        }

        $this->students->update($student, [
            'registrationStatus'    => 'approved',
            'barcodeValue'          => $this->students->generateUniqueBarcode(),
            'reviewedByLibrarianID' => $librarianID,
            'reviewedAt'            => now(),
        ]);

        $this->notifications->send(
            $student->studentID,
            'Your registration has been approved! You can now log in.',
            'registration_approved'
        );

        return $student->fresh();
    }

    public function reject(Student $student, int $librarianID): Student
    {
        if ($student->registrationStatus === 'approved') {
            throw ValidationException::withMessages(['student' => ['Cannot reject an already-approved student.']]);
        }

        $this->students->update($student, [
            'registrationStatus'    => 'rejected',
            'reviewedByLibrarianID' => $librarianID,
            'reviewedAt'            => now(),
        ]);

        $this->notifications->send(
            $student->studentID,
            'Your registration was not approved. Please contact the library for details.',
            'registration_rejected'
        );

        return $student->fresh();
    }
}
