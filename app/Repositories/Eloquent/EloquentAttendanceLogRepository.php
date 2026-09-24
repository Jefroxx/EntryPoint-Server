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
        return AttendanceLog::with('student.user')
            ->when($studentID, fn ($query) => $query->where('studentID', $studentID))
            ->when($activeOnly, fn ($query) => $query->whereNull('exitTime'))
            ->orderByDesc('entryTime')
            ->get();
    }

    public function paginate(?string $search, ?string $date, bool $activeOnly, int $perPage): LengthAwarePaginator
    {
        return AttendanceLog::with('student.user')
            ->when($search, fn ($query, $term) => $query->whereHas(
                'student',
                fn ($sq) => $sq->where('studentIDNumber', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($uq) => $uq->where('firstName', 'like', "%{$term}%")
                        ->orWhere('lastName', 'like', "%{$term}%"))
            ))
            ->when($date, fn ($query, $value) => $query->whereDate('entryTime', $value))
            ->when($activeOnly, fn ($query) => $query->whereNull('exitTime'))
            ->orderByDesc('entryTime')
            ->paginate($perPage);
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
        return AttendanceLog::whereNull('exitTime')->count();
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
        return AttendanceLog::whereBetween('entryTime', [$start, $end])
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
