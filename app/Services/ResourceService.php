<?php

namespace App\Services;

use App\Models\Resource;
use App\Models\ResourceUsageLog;
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
            'resourceType' => $validated['resourceType'],
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

        return $this->resources->update($resource, $validated)->fresh();
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

    public function startSession(array $validated, int $staffLibrarianID): ResourceUsageLog
    {
        $log = DB::transaction(function () use ($validated, $staffLibrarianID) {
            $resource = $this->resources->lockForUpdate($validated['resID']);

            if ($resource->status !== 'Available') {
                throw ValidationException::withMessages([
                    'resID' => ["This resource is currently '{$resource->status}' and cannot be assigned."],
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
     * Closes out a usage session and frees the resource. Now wrapped in a
     * transaction — the previous model-method version wrote the log and the
     * resource status in two separate, unguarded statements.
     */
    public function endSession(ResourceUsageLog $usageLog): ResourceUsageLog
    {
        if ($usageLog->endTime) {
            throw ValidationException::withMessages([
                'usageLog' => ['This session has already ended.'],
            ]);
        }

        DB::transaction(function () use ($usageLog) {
            $this->usageLogs->update($usageLog, ['endTime' => now()]);
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
