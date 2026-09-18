<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Student;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceLogController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'studentID' => ['nullable', 'integer'],
            'search'    => ['nullable', 'string', 'max:255'],
            'date'      => ['nullable', 'date'],
            'active'    => ['nullable', 'boolean'],
            'perPage'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AttendanceLog::with('student.user')
            ->when($validated['studentID'] ?? null, fn ($q, $studentID) => $q->where('studentID', $studentID))
            ->when($validated['search'] ?? null, function ($q, $term) {
                $q->whereHas('student.user', fn ($userQuery) => $userQuery
                    ->where('firstName', 'like', "%{$term}%")
                    ->orWhere('lastName', 'like', "%{$term}%"));
            })
            ->when($validated['date'] ?? null, fn ($q, $date) => $q->whereDate('entryTime', $date))
            ->when($request->boolean('active'), fn ($q) => $q->whereNull('exitTime'))
            ->orderByDesc('entryTime');

        return response()->json($query->paginate($validated['perPage'] ?? 15));
    }

    public function stats()
    {
        $todayLogs = AttendanceLog::whereDate('entryTime', today())->get();
        $completedToday = $todayLogs->whereNotNull('exitTime');

        $averageMinutes = $completedToday->isNotEmpty()
            ? $completedToday->avg(fn (AttendanceLog $log) => $log->entryTime->diffInMinutes($log->exitTime))
            : 0;

        return response()->json([
            'currentlyInLibrary'  => AttendanceLog::whereNull('exitTime')->count(),
            'totalVisitsToday'    => $todayLogs->count(),
            'averageMinutesToday' => (int) round($averageMinutes),
        ]);
    }

    /**
     * One barcode scan toggles attendance: opens a new entry if the student
     * has no open session, or closes their open session if they do.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'barcodeValue' => ['required', 'string'],
        ]);

        $student = Student::where('barcodeValue', $validated['barcodeValue'])->first();

        if (! $student || ! $student->isApproved()) {
            return response()->json([
                'message' => 'Barcode not recognized or the account is not yet approved.',
            ], 404);
        }

        $log = DB::transaction(function () use ($student) {
            $openLog = AttendanceLog::where('studentID', $student->studentID)
                ->whereNull('exitTime')
                ->latest('entryTime')
                ->first();

            if ($openLog) {
                $openLog->update(['exitTime' => now()]);

                return $openLog;
            }

            $alreadyVisitedToday = AttendanceLog::where('studentID', $student->studentID)
                ->whereDate('entryTime', today())
                ->exists();

            $log = AttendanceLog::create([
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

        SystemNotification::notify(
            $student->studentID,
            $isCheckIn
                ? "You've checked in to the library at {$log->entryTime->format('g:i A')}."
                : "You've checked out of the library at {$log->exitTime->format('g:i A')}.",
            $isCheckIn ? 'attendance_check_in' : 'attendance_check_out'
        );

        return response()->json([
            'message' => $isCheckIn ? 'Checked in.' : 'Checked out.',
            'log'     => $log,
        ], $isCheckIn ? 201 : 200);
    }

    /**
     * Consecutive-day visit streak: +1 if the student's last visit was
     * exactly yesterday, reset to 1 on a first-ever visit or a gap.
     * Only called on the first check-in of a new calendar day.
     */
    private function updateVisitStreak(Student $student): void
    {
        $lastVisit = AttendanceLog::where('studentID', $student->studentID)
            ->whereDate('entryTime', '<', today())
            ->orderByDesc('entryTime')
            ->first();

        $newStreak = ($lastVisit && $lastVisit->entryTime->isYesterday())
            ? $student->visitStreak + 1
            : 1;

        $student->update(['visitStreak' => $newStreak]);
    }
}
