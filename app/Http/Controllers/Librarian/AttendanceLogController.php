<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceScanRequest;
use App\Http\Requests\StoreVisitorLogRequest;
use App\Models\AttendanceLog;
use App\Services\AppSettings;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class AttendanceLogController extends Controller
{
    public function __construct(private AttendanceService $attendanceService)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->attendanceService->index(
            $request->query('search'),
            $request->query('date'),
            $request->boolean('active'),
            (int) ($request->query('perPage') ?? 15),
            in_array($request->query('status'), ['in', 'out'], true) ? $request->query('status') : null,
            in_array($request->query('who'), ['students', 'members', 'visitors'], true) ? $request->query('who') : null,
            $request->query('sort') === 'oldest' ? 'oldest' : null,
        ));
    }

    /** Whether the library session is open: from opening the scan station until its End session. */
    public function station()
    {
        return response()->json(['session' => AppSettings::stationSession()]);
    }

    /** Opening the scan station opens the library session (or keeps the one already open). */
    public function startStation(Request $request)
    {
        return response()->json(['session' => AppSettings::startStationSession($request->user()->fullName)]);
    }

    /** Ending the scan station: logs everyone still in the library out, and closes the library session. */
    public function closeAll()
    {
        $result = $this->attendanceService->closeAllOpenVisits();
        AppSettings::endStationSession();

        $people = $result['students'] + $result['visitors'];

        return response()->json([
            'message' => $people === 0
                ? 'Nobody was in the library.'
                : "{$people} ".($people === 1 ? 'person was' : 'people were').' logged out of the library.',
            ...$result,
        ]);
    }

    public function stats(Request $request)
    {
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;

        return response()->json($this->attendanceService->stats($date));
    }

    public function storeVisitor(StoreVisitorLogRequest $request)
    {
        $data = $request->validated();

        return response()->json([
            'message' => 'Visitor checked in.',
            'log'     => $this->attendanceService->logVisitor(trim($data['name']), trim($data['purpose'])),
        ], 201);
    }

    public function checkOutVisitor(AttendanceLog $log)
    {
        return response()->json([
            'message' => 'Visitor checked out.',
            'log'     => $this->attendanceService->checkOutVisitor($log),
        ]);
    }

    public function store(StoreAttendanceScanRequest $request)
    {
        $result = $this->attendanceService->scan($request->validated()['barcodeValue']);

        $isCheckIn = $result['action'] === 'check_in';
        $student = $result['student'];

        return response()->json([
            'message'         => $isCheckIn ? 'Checked in.' : 'Checked out.',
            'action'          => $result['action'],
            'log'             => $result['log'],
            'student'         => [
                'name'            => $student->user->fullName,
                'program'         => $student->academicProgram ?? $student->department,
                'memberIDNumber' => $student->memberIDNumber,
                'visitStreak'     => $student->visitStreak,
            ],
            'durationMinutes' => $result['durationMinutes'],
            'autoClosed'      => $result['autoClosed'],
            'endedSession'    => $result['endedSession'] ?? null,
        ], $isCheckIn ? 201 : 200);
    }
}
