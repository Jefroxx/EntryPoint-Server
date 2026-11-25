<?php

namespace App\Repositories\Eloquent;

use App\Models\AttendanceLog;
use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

class EloquentAttendanceLogRepository extends BaseRepository implements AttendanceLogRepositoryInterface
{
    public function __construct(AttendanceLog $model)
    {
        parent::__construct($model);
    }

    public function listWithFilters(?int $studentID, bool $activeOnly): Collection
    {
        return AttendanceLog::with(['student.user', 'checkedInByUser.librarian', 'checkedOutByUser.librarian'])
            ->when($studentID, fn ($query) => $query->where('studentID', $studentID))
            ->when($activeOnly, fn ($query) => $query->whereNull('exitTime'))
            ->orderByDesc('entryTime')
            ->get()
            ->each->withStaff();
    }

    public function paginate(?string $search, ?string $date, bool $activeOnly, int $perPage, ?string $status = null, ?string $who = null, ?string $sort = null): LengthAwarePaginator
    {
        return AttendanceLog::with(['student.user', 'checkedInByUser.librarian', 'checkedOutByUser.librarian'])
            ->when($search, fn ($query, $term) => $query->where(
                fn ($q) => $q->whereHas(
                    'student',
                    fn ($sq) => $sq->where('memberIDNumber', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")
                            ->orWhere('lastName', 'like', "%{$term}%"))
                )->orWhere('visitorName', 'like', "%{$term}%")
            ))
            ->when($date, fn ($query, $value) => $query->whereDate('entryTime', $value))
            ->when($activeOnly || $status === 'in', fn ($query) => $query->whereNull('exitTime'))
            ->when($status === 'out', fn ($query) => $query->whereNotNull('exitTime'))
            // Who: students, faculty and staff members, or walk-in visitors (no account).
            ->when($who === 'students', fn ($query) => $query->whereHas('student', fn ($sq) => $sq->where('memberType', 'student')))
            ->when($who === 'members', fn ($query) => $query->whereHas('student', fn ($sq) => $sq->whereIn('memberType', ['faculty', 'staff'])))
            ->when($who === 'visitors', fn ($query) => $query->whereNull('studentID'))
            ->orderBy('entryTime', $sort === 'oldest' ? 'asc' : 'desc')
            ->paginate($perPage)
            // The staff screens see who scanned each visit in and out.
            ->through(fn (AttendanceLog $log) => $log->withStaff());
    }

    public function recentForStudent(int $studentID, int $limit): Collection
    {
        return AttendanceLog::where('studentID', $studentID)
            ->orderByDesc('entryTime')
            ->limit($limit)
            ->get();
    }

    public function currentlyInLibraryCount(): int
    {
        // Today's visits only: one left open from an earlier day is not someone inside now (the nightly clean-up closes it).
        return AttendanceLog::whereNull('exitTime')->whereDate('entryTime', today())->count();
    }

    public function totalVisitsToday(): int
    {
        return AttendanceLog::whereDate('entryTime', today())->count();
    }

    public function averageMinutesToday(): float
    {
        $average = AttendanceLog::whereDate('entryTime', today())
            ->whereNotNull('exitTime')
            ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, entryTime, exitTime)) as avgMinutes'))
            ->value('avgMinutes');

        return $average ? round((float) $average, 1) : 0.0;
    }

    public function dayStats(string $date): array
    {
        $day = fn () => AttendanceLog::whereDate('entryTime', $date);

        $average = $day()->whereNotNull('exitTime')
            ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, entryTime, exitTime)) as avgMinutes'))
            ->value('avgMinutes');

        return [
            'visits'         => $day()->count(),
            'students'       => $day()->whereNotNull('studentID')->distinct()->count('studentID'),
            'visitors'       => $day()->whereNull('studentID')->count(),
            // No finished visit yet means there is no average to give (not an average of zero).
            'averageMinutes' => $average === null ? null : round((float) $average, 1),
        ];
    }

    public function openLogForStudent(int $studentID): ?AttendanceLog
    {
        return AttendanceLog::where('studentID', $studentID)
            ->whereNull('exitTime')
            ->latest('entryTime')
            ->first();
    }

    public function latestForStudent(int $studentID): ?AttendanceLog
    {
        return AttendanceLog::where('studentID', $studentID)
            ->latest('entryTime')
            ->first();
    }

    public function openLogs(): Collection
    {
        return AttendanceLog::whereNull('exitTime')->orderBy('entryTime')->get();
    }

    public function staleOpenLogs(): Collection
    {
        return AttendanceLog::whereNull('exitTime')
            ->where('entryTime', '<', today())
            ->get();
    }

    public function hasVisitedToday(int $studentID): bool
    {
        return AttendanceLog::where('studentID', $studentID)
            ->whereDate('entryTime', today())
            ->exists();
    }

    public function lastVisitBeforeToday(int $studentID): ?AttendanceLog
    {
        return AttendanceLog::where('studentID', $studentID)
            ->whereDate('entryTime', '<', today())
            ->orderByDesc('entryTime')
            ->first();
    }

    public function averageMinutesBetween(\DateTimeInterface $start, \DateTimeInterface $end): float
    {
        $average = AttendanceLog::whereBetween('entryTime', [$start, $end])
            ->whereNotNull('exitTime')
            ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, entryTime, exitTime)) as avgMinutes'))
            ->value('avgMinutes');

        return $average ? round((float) $average, 1) : 0.0;
    }

    public function visitDaysBetween(\DateTimeInterface $start, \DateTimeInterface $end): BaseCollection
    {
        // Walk-in visitors have no account, so they aren't part of the per-student visit numbers.
        return AttendanceLog::whereBetween('entryTime', [$start, $end])
            ->whereNotNull('studentID')
            ->selectRaw('studentID, DATE(entryTime) as day')
            ->toBase()
            ->get()
            ->map(fn ($row) => ['studentID' => (int) $row->studentID, 'day' => (string) $row->day]);
    }

    public function visitorsByProgramBetween(\DateTimeInterface $start, \DateTimeInterface $end): BaseCollection
    {
        $logs = (new AttendanceLog)->getTable();

        return AttendanceLog::join('students', 'students.studentID', '=', "{$logs}.studentID")
            ->whereBetween("{$logs}.entryTime", [$start, $end])
            // "Students who visited, by program": faculty and staff have no program, so they aren't part of this chart.
            ->where('students.memberType', 'student')
            ->selectRaw("students.academicProgram as program, COUNT(DISTINCT {$logs}.studentID) as total")
            ->groupBy('students.academicProgram')
            ->pluck('total', 'program');
    }

    public function countByWeekdayBetween(\DateTimeInterface $start, \DateTimeInterface $end): BaseCollection
    {
        return AttendanceLog::whereBetween('entryTime', [$start, $end])
            ->selectRaw('DAYOFWEEK(entryTime) as dow, COUNT(*) as total')
            ->groupBy('dow')
            ->pluck('total', 'dow');
    }
}
