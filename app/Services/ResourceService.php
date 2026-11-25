<?php

namespace App\Services;

use App\Models\Resource;
use App\Models\ResourceUsageLog;
use App\Repositories\Contracts\AttendanceLogRepositoryInterface;
use App\Repositories\Contracts\ResourceRepositoryInterface;
use App\Repositories\Contracts\ResourceUsageLogRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResourceService
{
    public function __construct(
        private ResourceRepositoryInterface $resources,
        private ResourceUsageLogRepositoryInterface $usageLogs,
        private NotificationService $notifications,
        private AttendanceLogRepositoryInterface $attendanceLogs,
    ) {
    }

    public function listForLibrarian(?string $resourceType, ?string $status): Collection
    {
        return $this->resources->listWithFilters($resourceType, $status);
    }

    public function listForStudent(?string $resourceType): Collection
    {
        return $this->resources->studentListWithFilters($resourceType);
    }

    public function create(array $validated): Resource
    {
        return $this->resources->create([
            'uuid'         => Str::uuid(),
            'resourceType' => $this->canonicalType($validated['resourceType']),
            'name'         => $validated['name'],
            'status'       => 'Available',
        ]);
    }

    public function update(Resource $resource, array $validated): Resource
    {
        if (isset($validated['status']) && $resource->status === 'In Use') {
            throw ValidationException::withMessages([
                'status' => ['Cannot change availability while the resource is currently in use. End the active session first.'],
            ]);
        }

        if (isset($validated['resourceType'])) {
            $validated['resourceType'] = $this->canonicalType($validated['resourceType']);
        }

        return $this->resources->update($resource, $validated)->fresh();
    }

    /** The type as it is written on the list (the request may have come in other capitals). */
    private function canonicalType(string $type): string
    {
        return \App\Models\ResourceType::where('name', $type)->value('name') ?? $type;
    }

    public function delete(Resource $resource): void
    {
        if ($resource->status === 'In Use') {
            throw ValidationException::withMessages([
                'resource' => ['Cannot delete: this resource is currently in use.'],
            ]);
        }

        $this->resources->delete($resource);
    }

    public function listUsageLogs(?int $resID, bool $activeOnly): Collection
    {
        return $this->usageLogs->listWithFilters($resID, $activeOnly);
    }

    public function pagedUsageLogs(?string $search, ?string $status, ?string $type, ?int $resID, ?string $date, int $perPage)
    {
        return $this->usageLogs->paginate($search, $status, $type, $resID, $date, $perPage);
    }

    /** Sessions still running from before today (the library has long closed). */
    public function staleActiveSessions()
    {
        return $this->usageLogs->staleActive();
    }

    /**
     * The library is closed, so nothing is in use any more: every running session ends. $at is the closing time to record
     * (the clean-up runs after the fact); with none, it is now. Returns how many ended.
     */
    public function endAllActiveSessions(?\Carbon\CarbonInterface $at = null): int
    {
        $sessions = $this->usageLogs->allActive();
        $sessions->each(fn (ResourceUsageLog $session) => $this->endSession($session, $at));

        return $sessions->count();
    }

    public function startSession(array $validated, int $staffLibrarianID): ResourceUsageLog
    {
        $log = DB::transaction(function () use ($validated, $staffLibrarianID) {
            $resource = $this->resources->lockForUpdate($validated['resID']);

            if ($resource->status !== 'Available') {
                throw ValidationException::withMessages([
                    'resID' => ["This resource is currently '{$resource->status}' and cannot be assigned."],
                ]);
            }

            // A session is for someone who is in the library: they must have scanned in today and not out again.
            $visit = $this->attendanceLogs->openLogForStudent($validated['studentID']);
            if (! $visit || ! $visit->entryTime->isToday()) {
                throw ValidationException::withMessages([
                    'studentID' => ['This student is not logged in to the library. Scan their ID at the Station first.'],
                ]);
            }

            if ($this->usageLogs->hasActiveSessionForStudent($validated['studentID'])) {
                throw ValidationException::withMessages([
                    'studentID' => ['This student already has an active session on another resource.'],
                ]);
            }

            $log = $this->usageLogs->create([
                'uuid'             => Str::uuid(),
                'resID'            => $resource->resID,
                'studentID'        => $validated['studentID'],
                'staffLibrarianID' => $staffLibrarianID,
                'startTime'        => now(),
            ]);

            $this->resources->update($resource, ['status' => 'In Use']);

            return $log;
        });

        $log->load(['resource', 'student.user']);

        $this->notifications->send(
            $log->studentID,
            "You've started using {$log->resource->name}.",
            'resource_session_started'
        );

        return $log;
    }

    /**
     * Logging out of the library also ends the student's running session, so the computer is freed without the
     * librarian having to remember. The librarian can still end a session by hand before that.
     * Returns the session that was ended, or null when the student had none.
     */
    public function endSessionForStudent(int $studentID, ?\Carbon\CarbonInterface $at = null): ?ResourceUsageLog
    {
        $session = $this->usageLogs->activeForStudent($studentID);

        return $session ? $this->endSession($session, $at) : null;
    }

    /**
     * Closes out a usage session and frees the resource. Now wrapped in a
     * transaction — the previous model-method version wrote the log and the
     * resource status in two separate, unguarded statements.
     */
    public function endSession(ResourceUsageLog $usageLog, ?\Carbon\CarbonInterface $at = null): ResourceUsageLog
    {
        if ($usageLog->endTime) {
            throw ValidationException::withMessages([
                'usageLog' => ['This session has already ended.'],
            ]);
        }

        // Never before it started (a session closed at a closing time that is earlier than the start).
        $end = $at && $at->greaterThan($usageLog->startTime) ? $at : now();

        DB::transaction(function () use ($usageLog, $end) {
            $this->usageLogs->update($usageLog, ['endTime' => $end]);
            $this->resources->update($usageLog->resource, ['status' => 'Available']);
        });

        $usageLog->load(['resource', 'student.user']);

        $this->notifications->send(
            $usageLog->studentID,
            "Your session on {$usageLog->resource->name} has ended.",
            'resource_session_ended'
        );

        return $usageLog;
    }
}
