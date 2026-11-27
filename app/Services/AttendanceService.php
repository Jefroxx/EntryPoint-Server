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
        private ResourceService $resources,
    ) {
    }

    public function listWithFilters(?int $studentID, bool $activeOnly): Collection
    {
        return $this->attendanceLogs->listWithFilters($studentID, $activeOnly);
    }

    public function index(?string $search, ?string $date, bool $activeOnly, int $perPage, ?string $status = null, ?string $who = null, ?string $sort = null): LengthAwarePaginator
    {
        return $this->attendanceLogs->paginate($search, $date, $activeOnly, $perPage, $status, $who, $sort);
    }

    /**
     * The live numbers (who is in the library now, today's visits) plus `day`: the same picture for any one date
     * (Y-m-d, today when none is given), so the Attendance page can browse the log day by day.
     *
     * @return array{currentlyInLibrary: int, totalVisitsToday: int, averageMinutesToday: float, date: string, day: array}
     */
    public function stats(?string $date = null): array
    {
        $date = $date ?: today()->toDateString();
        $open = $this->attendanceLogs->openLogs();

        return [
            // Who is inside right now, so "End session" can say how many it is about to log out.
            'inside'              => [
                'students' => $open->whereNotNull('studentID')->count(),
                'visitors' => $open->whereNull('studentID')->count(),
                // Of those, the ones whose visit started on an earlier day (left open, waiting for the nightly clean-up).
                'earlier'  => [
                    'students' => $open->whereNotNull('studentID')->filter(fn ($log) => ! $log->entryTime->isToday())->count(),
                    'visitors' => $open->whereNull('studentID')->filter(fn ($log) => ! $log->entryTime->isToday())->count(),
                ],
            ],
            'currentlyInLibrary'  => $this->attendanceLogs->currentlyInLibraryCount(),
            'totalVisitsToday'    => $this->attendanceLogs->totalVisitsToday(),
            'averageMinutesToday' => $this->attendanceLogs->averageMinutesToday(),
            'date'                => $date,
            'day'                 => $this->attendanceLogs->dayStats($date),
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
    /** A read as a librarian can see it in a message: invisible characters as "·", kept short. */
    public static function shownRead(string $code): string
    {
        return mb_strimwidth(trim(preg_replace('/[\x00-\x1F\x7F]/', '·', $code)), 0, 40, '…');
    }

    public function scan(string $code): array
    {
        $result = DB::transaction(function () use ($code) {
            // Row lock: two scanners reading the same student at once can't open two visits.
            $student = $this->students->findForScan($code, lock: true);

            if (! $student) {
                // Say what was read: a code that won't match can then be told apart from a different barcode being picked up.
                throw ValidationException::withMessages([
                    'barcodeValue' => ['Barcode not recognized: read "' . self::shownRead($code) . '". It isn\'t a student ID, a receipt, a slip, a book label or a facility label.'],
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
                $log = $this->attendanceLogs->update($open, ['exitTime' => now(), 'checkedOutBy' => auth()->id()]);

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
                // The staff account that scanned the card: so a shared desk can tell who checked the student in.
                'checkedInBy' => auth()->id(),
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
        $log = $result['log']->withStaff();
        $result['log'] = $log;

        // Leaving the library also ends a computer or room session the student still has open.
        $result['endedSession'] = null;
        if (! $isCheckIn && ($ended = $this->resources->endSessionForStudent($result['student']->studentID))) {
            $result['endedSession'] = [
                'name'    => $ended->resource->name,
                'minutes' => (int) $ended->startTime->diffInMinutes($ended->endTime),
            ];
        }

        $this->notifications->send(
            $result['student']->studentID,
            $isCheckIn
                ? "You've checked in to the library at {$log->entryTime->format('g:i A')}."
                : "You've checked out of the library at {$log->exitTime->format('g:i A')}.",
            $isCheckIn ? 'attendance_check_in' : 'attendance_check_out'
        );

        // The staff are told too, as the attendance diagrams say. Only arrivals (a departure is not news), and not the
        // librarian who scanned the card, who is looking at it on the station.
        if ($isCheckIn) {
            $this->notifications->notifyAllLibrarians(
                "{$result['student']->user->fullName} checked in to the library at {$log->entryTime->format('g:i A')}.",
                'student_checked_in',
                auth()->id()
            );
        }

        return $result;
    }

    /** A walk-in with no account: the librarian types the name and the purpose of the visit. */
    public function logVisitor(string $name, string $purpose): AttendanceLog
    {
        return $this->attendanceLogs->create([
            'uuid'           => Str::uuid(),
            'studentID'      => null,
            'visitorName'    => $name,
            'visitorPurpose' => $purpose,
            'entryTime'      => now(),
            'checkedInBy'    => auth()->id(),
        ])->withStaff();
    }

    /** Closes a visitor's open visit by hand (they have no card to scan on the way out). */
    public function checkOutVisitor(AttendanceLog $log): AttendanceLog
    {
        if ($log->studentID !== null) {
            throw ValidationException::withMessages(['log' => ['Students check out by scanning their ID.']]);
        }

        if ($log->exitTime) {
            throw ValidationException::withMessages(['log' => ['This visitor is already checked out.']]);
        }

        return $this->attendanceLogs->update($log, ['exitTime' => now(), 'checkedOutBy' => auth()->id()])->withStaff();
    }

    /**
     * Closes a visit that was never scanned out, at the configured closing time (or N hours after
     * entry, see config/attendance.php) instead of "now", so the recorded stay stays believable.
     *
     * @return array{logID: int, exitTime: string}
     */
    public function closeStale(AttendanceLog $log): array
    {
        $exit = $this->autoCloseTime($log->entryTime);

        $this->attendanceLogs->update($log, ['exitTime' => $exit]);

        return ['logID' => $log->logID, 'exitTime' => $exit->toIso8601String()];
    }

    /** When something that started at $start is closed for it: the closing time of that day (or N hours later, see config/attendance.php). */
    private function autoCloseTime(\Carbon\CarbonInterface $start): \Carbon\CarbonInterface
    {
        $exit = config('attendance.auto_close') === 'hours'
            ? $start->copy()->addHours((int) config('attendance.auto_close_hours'))
            : $start->copy()->setTimeFromTimeString((string) config('attendance.closing_time'));

        return $exit->lessThan($start) ? $start->copy() : $exit; // came in after closing time
    }

    /**
     * Ending the scan station: everyone still inside is logged out (students and walk-in visitors), and any
     * computer or room session a student still has is ended with it. A visit left over from an earlier day is closed
     * the way the nightly clean-up does (at the closing time of the day it started); today's closes now.
     *
     * @return array{students: int, visitors: int, sessionsEnded: int}
     */
    public function closeAllOpenVisits(): array
    {
        $counts = ['students' => 0, 'visitors' => 0, 'sessionsEnded' => 0];

        foreach ($this->attendanceLogs->openLogs() as $log) {
            $today = $log->entryTime->isToday();

            if ($today) {
                $this->attendanceLogs->update($log, ['exitTime' => now(), 'checkedOutBy' => auth()->id()]);
            } else {
                $this->closeStale($log);
            }

            if ($log->studentID === null) {
                $counts['visitors']++;
                continue;
            }

            $counts['students']++;

            // Told, like a student who scans out, but why: nobody scanned. (A visit left from an earlier day is
            // just tidied up; a message about it now would only confuse.)
            if ($today) {
                $this->notifications->send(
                    $log->studentID,
                    "You've been checked out of the library at ".now()->format('g:i A').' because the library closed for the day.',
                    'attendance_check_out'
                );
            }

            if ($this->resources->endSessionForStudent($log->studentID)) {
                $counts['sessionsEnded']++;
            }
        }

        // The library is closed, so nothing is in use: any session still running (a facility started for someone whose
        // visit was already closed, say) ends with it.
        $counts['sessionsEnded'] += $this->resources->endAllActiveSessions();

        return $counts;
    }

    /** Nightly clean-up (attendance:close-stale): closes every visit still open from an earlier day. */
    public function closeStaleVisits(): int
    {
        $logs = $this->attendanceLogs->staleOpenLogs();

        $logs->each(fn (AttendanceLog $log) => $this->closeStale($log));

        // A computer or room session left running from an earlier day ends at that day's closing time too:
        // when the visits are closed the library is closed, so nothing can still be in use.
        $this->resources->staleActiveSessions()->each(fn ($session) => $this->resources->endSession($session, $this->autoCloseTime($session->startTime)));

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
