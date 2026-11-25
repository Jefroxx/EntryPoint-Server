<?php

namespace App\Repositories\Eloquent;

use App\Models\Student;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EloquentStudentRepository extends BaseRepository implements StudentRepositoryInterface
{
    public function __construct(Student $model)
    {
        parent::__construct($model);
    }

    public function creditPoints(Student $student, int $delta): Student
    {
        // Added in the database, not from the number this request happened to read: two requests that credit points
        // at the same moment (a loan returned while a reward is redeemed) would otherwise overwrite each other.
        // Never below zero, whatever the delta.
        Student::whereKey($student->getKey())->update([
            'knowledgeScore' => DB::raw('GREATEST(CAST(`knowledgeScore` AS SIGNED) + ' . (int) $delta . ', 0)'),
        ]);

        return $student->refresh();
    }

    public function findByBarcode(string $barcode): ?Student
    {
        return Student::where('barcodeValue', $barcode)->first();
    }

    public function findForScan(string $code, bool $lock = false): ?Student
    {
        $query = Student::where(fn ($where) => $where->where('barcodeValue', $code)->orWhere('memberIDNumber', $code));

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

    public function approvedIDs(): array
    {
        return Student::where('registrationStatus', 'approved')->pluck('studentID')->map(fn ($id) => (int) $id)->all();
    }

    public function readyForReviewCount(): int
    {
        return Student::where('registrationStatus', 'pending')
            ->whereHas('user', fn ($query) => $query->whereNotNull('emailVerifiedAt'))
            ->count();
    }

    /** "members" is faculty and staff together (everyone who is not a student); anything else is one exact type. */
    private function ofMemberType($query, string $type)
    {
        // A member whose data was removed (see PrivacyService) is an empty shell kept for the library's records: not a person to look up.
        return $type === 'members'
            ? $query->whereIn('memberType', ['faculty', 'staff'])->where('memberIDNumber', 'not like', 'REMOVED-%')
            : $query->where('memberType', $type);
    }

    public function programs(?string $memberType = null): array
    {
        $column = $memberType === 'student' ? 'academicProgram' : 'department';

        return Student::when($memberType, fn ($query, $value) => $this->ofMemberType($query, $value))
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    public function countsByMemberType(): array
    {
        $counts = $this->ofMemberType(Student::query(), 'members')->selectRaw('memberType, COUNT(*) as total')->groupBy('memberType')->pluck('total', 'memberType');

        return ['faculty' => (int) $counts->get('faculty', 0), 'staff' => (int) $counts->get('staff', 0)];
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

    public function paginate(?string $search, ?string $program, ?string $status, int $perPage, ?string $memberType = null, ?string $sort = null, bool $inLibrary = false): LengthAwarePaginator
    {
        // The oldest registration first by default where there is a queue to work through (pending), newest first elsewhere.
        $oldestFirst = ($sort ?? ($status === 'pending' ? 'oldest' : 'newest')) === 'oldest';

        return Student::with('user')
            ->when($memberType, fn ($query, $value) => $this->ofMemberType($query, $value))
            ->when($search, fn ($query, $term) => $query->where(function ($q) use ($term) {
                // The ID card's barcode (what the checkout desk scans) matches exactly.
                $q->where('memberIDNumber', 'like', "%{$term}%")
                    ->orWhere('barcodeValue', $term)
                    ->orWhereHas('user', fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")
                        ->orWhere('lastName', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%"));
            }))
            // A student has a program; faculty and staff have a department, so the same filter narrows by that instead.
            ->when($program, fn ($query, $value) => $query->where($memberType === 'student' ? 'academicProgram' : 'department', $value))
            ->when($status, fn ($query, $value) => $query->where('registrationStatus', $value))
            // Only those who have confirmed their email can be approved, so only they are waiting for the librarian.
            ->when($status === 'pending', fn ($query) => $query->whereHas('user', fn ($uq) => $uq->whereNotNull('emailVerifiedAt')))
            // Only those who scanned in today and have not scanned out (who a computer or room session can be for).
            ->when($inLibrary, fn ($query) => $query->whereHas('attendanceLogs', fn ($log) => $log->whereNull('exitTime')->whereDate('entryTime', today())))
            ->orderBy('studentID', $oldestFirst ? 'asc' : 'desc')
            ->paginate($perPage);
    }

    public function countsByRegistrationStatus(?string $memberType = null): array
    {
        $counts = Student::when($memberType, fn ($query, $value) => $this->ofMemberType($query, $value))
            // A registration that has not confirmed its email is not waiting for a librarian yet, so it is not counted.
            ->where(fn ($query) => $query->where('registrationStatus', '!=', 'pending')
                ->orWhereHas('user', fn ($uq) => $uq->whereNotNull('emailVerifiedAt')))
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
