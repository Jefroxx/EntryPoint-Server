<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Student;
use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(
        private AttendanceLogRepositoryInterface $attendanceLogs,
        private StudentRepositoryInterface $students,
        private NotificationService $notifications,
        private AchievementService $achievements,
    ) {
    }

    public function listWithFilters(?int $studentID, bool $activeOnly): Collection
    {
        return $this->attendanceLogs->listWithFilters($studentID, $activeOnly);
    }

    public function index(?string $search, ?string $date, bool $activeOnly, int $perPage): LengthAwarePaginator
    {
        return $this->attendanceLogs->paginate($search, $date, $activeOnly, $perPage);
    }

    /**
     * @return array{currentlyInLibrary: int, totalVisitsToday: int, averageMinutesToday: float}
     */
    public function stats(): array
    {
        return [
            'currentlyInLibrary'  => $this->attendanceLogs->currentlyInLibraryCount(),
            'totalVisitsToday'    => $this->attendanceLogs->totalVisitsToday(),
            'averageMinutesToday' => $this->attendanceLogs->averageMinutesToday(),
        ];
    }

    /**
     * One barcode scan toggles attendance: opens a new entry if the student
     * has no open session, or closes their open session if they do.
     */
    public function scan(string $barcodeValue): AttendanceLog
    {
        $student = $this->students->findByBarcode($barcodeValue);

        if (! $student || ! $student->isApproved()) {
            throw ValidationException::withMessages([
                'barcodeValue' => ['Barcode not recognized or the account is not yet approved.'],
            ]);
        }

        $log = DB::transaction(function () use ($student) {
            $openLog = $this->attendanceLogs->openLogForStudent($student->studentID);

            if ($openLog) {
                return $this->attendanceLogs->update($openLog, ['exitTime' => now()]);
            }

            $alreadyVisitedToday = $this->attendanceLogs->hasVisitedToday($student->studentID);

            $log = $this->attendanceLogs->create([
                'uuid'      => Str::uuid(),
                'studentID' => $student->studentID,
                'entryTime' => now(),
            ]);

            if (! $alreadyVisitedToday) {
                $this->updateVisitStreak($student);
            }

            return $log;
        });

        $isCheckIn = $log->wasRecentlyCreated;

        $this->notifications->send(
            $student->studentID,
            $isCheckIn
                ? "You've checked in to the library at {$log->entryTime->format('g:i A')}."
                : "You've checked out of the library at {$log->exitTime->format('g:i A')}.",
            $isCheckIn ? 'attendance_check_in' : 'attendance_check_out'
        );

        return $log;
    }

    /**
     * Consecutive-day visit streak: +1 if the student's last visit was
     * exactly yesterday, reset to 1 on a first-ever visit or a gap. Only
     * called on the first check-in of a new calendar day.
     */
    private function updateVisitStreak(Student $student): void
    {
        $lastVisit = $this->attendanceLogs->lastVisitBeforeToday($student->studentID);

        $newStreak = ($lastVisit && $lastVisit->entryTime->isYesterday())
            ? $student->visitStreak + 1
            : 1;

        $this->students->update($student, ['visitStreak' => $newStreak]);

        // Was implicit via Student::booted() before that hook was removed;
        // now every visitStreak-raising write must trigger this explicitly.
        $this->achievements->evaluateForStudent($student);
    }
}
