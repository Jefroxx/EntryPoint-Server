<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\ResourceUsageLog;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResourceUsageLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ResourceUsageLog::with(['resource', 'student.user', 'staffLibrarian.user'])
            ->orderByDesc('startTime');

        if ($request->filled('resID')) {
            $query->where('resID', $request->query('resID'));
        }

        if ($request->boolean('active')) {
            $query->whereNull('endTime');
        }

        return response()->json(['usageLogs' => $query->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'resID'     => ['required', 'integer', 'exists:resources,resID'],
            'studentID' => ['required', 'integer', 'exists:students,studentID'],
        ]);

        $log = DB::transaction(function () use ($validated, $request) {
            $resource = Resource::lockForUpdate()->findOrFail($validated['resID']);

            if (! $resource->isAvailable()) {
                throw ValidationException::withMessages([
                    'resID' => ["This resource is currently '{$resource->status}' and cannot be assigned."],
                ]);
            }

            $alreadyInUse = ResourceUsageLog::where('studentID', $validated['studentID'])
                ->whereNull('endTime')
                ->exists();

            if ($alreadyInUse) {
                throw ValidationException::withMessages([
                    'studentID' => ['This student already has an active session on another resource.'],
                ]);
            }

            $log = ResourceUsageLog::create([
                'uuid'             => Str::uuid(),
                'resID'            => $resource->resID,
                'studentID'        => $validated['studentID'],
                'staffLibrarianID' => $request->user()->librarian->librarianID,
                'startTime'        => now(),
            ]);

            $resource->update(['status' => 'In Use']);

            return $log;
        });

        $log->load(['resource', 'student.user']);

        SystemNotification::notify(
            $log->studentID,
            "You've started using {$log->resource->name}.",
            'resource_session_started'
        );

        return response()->json([
            'message'   => 'Session started.',
            'usageLog'  => $log,
        ], 201);
    }

    public function end(ResourceUsageLog $usageLog)
    {
        if ($usageLog->endTime) {
            return response()->json(['message' => 'This session has already ended.'], 422);
        }

        $usageLog->endSession();
        $usageLog->load(['resource', 'student.user']);

        SystemNotification::notify(
            $usageLog->studentID,
            "Your session on {$usageLog->resource->name} has ended.",
            'resource_session_ended'
        );

        return response()->json([
            'message'  => 'Session ended.',
            'usageLog' => $usageLog,
        ]);
    }
}
