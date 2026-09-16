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
        $query = AttendanceLog::with('student.user')->orderByDesc('entryTime');

        if ($request->filled('studentID')) {
            $query->where('studentID', $request->query('studentID'));
        }

        if ($request->boolean('active')) {
            $query->whereNull('exitTime');
        }

        return response()->json(['attendanceLogs' => $query->get()]);
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
