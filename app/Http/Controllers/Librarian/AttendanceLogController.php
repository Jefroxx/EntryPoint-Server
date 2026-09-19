<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttendanceScanRequest;
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
            (int) ($request->query('perPage') ?? 15)
        ));
    }

    public function stats()
    {
        return response()->json($this->attendanceService->stats());
    }

    public function store(StoreAttendanceScanRequest $request)
    {
        $log = $this->attendanceService->scan($request->validated()['barcodeValue']);
        $isCheckIn = $log->wasRecentlyCreated;

        return response()->json([
            'message' => $isCheckIn ? 'Checked in.' : 'Checked out.',
            'log'     => $log,
        ], $isCheckIn ? 201 : 200);
    }
}
