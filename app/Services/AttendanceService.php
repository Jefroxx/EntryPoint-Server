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
     * One scan toggles attendance: opens a visit if the student has none open, or closes the open
     * one. A second scan inside the double-scan window is refused, and a visit left open from an
     * earlier day is closed first (see closeStale()) so today's scan becomes a fresh check-in
     * rather than a bogus check-out.
     *
     * @return array{action: string, log: AttendanceLog, student: Student, durationMinutes: ?int, autoClosed: array}
     */
    public function scan(string $code): array
    {
        $result = DB::transaction(function () use ($code) {
            // Row lock: two scanners reading the same student at once can't open two visits.
            $student = $this->students->findForScan($code, lock: true);

            if (! $student) {
                throw ValidationException::withMessages([
                    'barcodeValue' => ['Barcode not recognized.'],
                ]);
            }

            if (! $student->isApproved()) {
                throw ValidationException::withMessages([
                    'barcodeValue' => ['This account is not approved yet.'],
                ]);
            }

            $latest = $this->attendanceLogs->latestForStudent($student->studentID);
            $lastScanAt = $latest ? ($latest->exitTime ?? $latest->entryTime) : null;
            $window = (int) config('attendance.double_scan_seconds');

            if ($lastScanAt && ($secondsAgo = (int) $lastScanAt->diffInSeconds(now())) < $window) {
                throw ValidationException::withMessages([
                    'barcodeValue' => ['Just scanned. Try again in ' . ($window - $secondsAgo) . 's.'],
                ]);
            }

            $open = $this->attendanceLogs->openLogForStudent($student->studentID);
            $autoClosed = [];

            if ($open && ! $open->entryTime->isToday()) {
                $autoClosed[] = $this->closeStale($open);
                $open = null;
            }

            if ($open) {
                $log = $this->attendanceLogs->update($open, ['exitTime' => now()]);

                return [
                    'action'          => 'check_out',
                    'log'             => $log,
                    'student'         => $student,
                    'durationMinutes' => (int) $log->entryTime->diffInMinutes($log->exitTime),
                    'autoClosed'      => $autoClosed,
                ];
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

            return [
                'action'          => 'check_in',
                'log'             => $log,
                'student'         => $student,
                'durationMinutes' => null,
                'autoClosed'      => $autoClosed,
            ];
        });

        $isCheckIn = $result['action'] === 'check_in';
        $log = $result['log'];

        $this->notifications->send(
            $result['student']->studentID,
            $isCheckIn
                ? "You've checked in to the library at {$log->entryTime->format('g:i A')}."
                : "You've checked out of the library at {$log->exitTime->format('g:i A')}.",
            $isCheckIn ? 'attendance_check_in' : 'attendance_check_out'
        );

        return $result;
    }

    /**
     * Closes a visit that was never scanned out, at the configured closing time (or N hours after
     * entry, see config/attendance.php) instead of "now", so the recorded stay stays believable.
     *
     * @return array{logID: int, exitTime: string}
     */
    public function closeStale(AttendanceLog $log): array
    {
        $exit = config('attendance.auto_close') === 'hours'
            ? $log->entryTime->copy()->addHours((int) config('attendance.auto_close_hours'))
            : $log->entryTime->copy()->setTimeFromTimeString((string) config('attendance.closing_time'));

        if ($exit->lessThan($log->entryTime)) {
            $exit = $log->entryTime->copy(); // came in after closing time
        }

        $this->attendanceLogs->update($log, ['exitTime' => $exit]);

        return ['logID' => $log->logID, 'exitTime' => $exit->toIso8601String()];
    }

    /** Nightly clean-up (attendance:close-stale): closes every visit still open from an earlier day. */
    public function closeStaleVisits(): int
    {
        $logs = $this->attendanceLogs->staleOpenLogs();

        $logs->each(fn (AttendanceLog $log) => $this->closeStale($log));

        return $logs->count();
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
