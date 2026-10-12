<?php

namespace App\Repositories\Eloquent;

use App\Models\Student;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class EloquentStudentRepository extends BaseRepository implements StudentRepositoryInterface
{
    public function __construct(Student $model)
    {
        parent::__construct($model);
    }

    public function creditPoints(Student $student, int $delta): Student
    {
        $student->update(['knowledgeScore' => $student->knowledgeScore + $delta]);

        return $student;
    }

    public function findByBarcode(string $barcode): ?Student
    {
        return Student::where('barcodeValue', $barcode)->first();
    }

    public function findForScan(string $code, bool $lock = false): ?Student
    {
        $query = Student::where(fn ($where) => $where->where('barcodeValue', $code)->orWhere('studentIDNumber', $code));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function notHavingAchievement(int $achievementID): Collection
    {
        return Student::whereDoesntHave(
            'achievements',
            fn ($query) => $query->where('achievements.achievementID', $achievementID)
        )->get();
    }

    public function approvedCount(): int
    {
        return Student::where('registrationStatus', 'approved')->count();
    }

    public function readyForReviewCount(): int
    {
        return Student::where('registrationStatus', 'pending')
            ->whereHas('user', fn ($query) => $query->whereNotNull('emailVerifiedAt'))
            ->count();
    }

    public function approvedCountByProgram(): \Illuminate\Support\Collection
    {
        // "Students by program": faculty and staff members have a department, not a program, so they aren't counted.
        return Student::where('registrationStatus', 'approved')
            ->where('memberType', 'student')
            ->selectRaw('academicProgram as program, COUNT(*) as total')
            ->groupBy('academicProgram')
            ->pluck('total', 'program');
    }

    public function generateUniqueBarcode(): string
    {
        do {
            $code = 'STI-' . strtoupper(Str::random(10));
        } while ($this->existsBy('barcodeValue', $code));

        return $code;
    }

    public function paginate(?string $search, ?string $program, ?string $status, int $perPage, ?string $memberType = null): LengthAwarePaginator
    {
        return Student::with('user')
            ->when($memberType, fn ($query, $value) => $query->where('memberType', $value))
            ->when($search, fn ($query, $term) => $query->where(function ($q) use ($term) {
                $q->where('studentIDNumber', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")
                        ->orWhere('lastName', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%"));
            }))
            ->when($program, fn ($query, $value) => $query->where('academicProgram', $value))
            ->when($status, fn ($query, $value) => $query->where('registrationStatus', $value))
            ->orderByDesc('studentID')
            ->paginate($perPage);
    }

    public function countsByRegistrationStatus(?string $memberType = null): array
    {
        $counts = Student::when($memberType, fn ($query, $value) => $query->where('memberType', $value))
            ->selectRaw('registrationStatus, COUNT(*) as total')
            ->groupBy('registrationStatus')
            ->pluck('total', 'registrationStatus');

        return [
            'total'    => (int) $counts->sum(),
            'pending'  => (int) $counts->get('pending', 0),
            'approved' => (int) $counts->get('approved', 0),
            'rejected' => (int) $counts->get('rejected', 0),
        ];
    }
}
