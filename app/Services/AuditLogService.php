<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BookCopy;
use App\Models\Loan;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The audit log: who did what. Staff writes are recorded from the request (see LogStaffActivity and config/audit.php);
 * account and role changes are recorded by the admin services with record().
 */
class AuditLogService
{
    public function __construct(private StaffPermissionService $permissions)
    {
    }

    public function record(?User $actor, string $action, string $summary): void
    {
        AuditLog::create([
            'actorID'   => $actor?->userID,
            'actorName' => $actor ? $actor->fullName : 'System',
            'actorRole' => $actor ? $this->permissions->roleOf($actor) : 'system',
            'action'    => $action,
            'summary'   => mb_substr($summary, 0, 255),
        ]);
    }

    /** Records a successful staff request if config/audit.php knows it; anything else is ignored. */
    public function recordRequest(User $actor, Request $request): void
    {
        $route = $request->route();
        if (! $route) {
            return;
        }

        $key = $request->method() . ' ' . preg_replace('#^api/#', '', $route->uri());
        $entry = config('audit.actions')[$key] ?? null;
        if (! $entry) {
            return;
        }

        [$action, $label] = $entry;
        $subject = $this->subjectOf($request);

        $this->record($actor, $action, $subject ? "{$label}: {$subject}" : $label);
    }

    /** Names the record the request was about: a bound model on the route, or the title/name that was sent. */
    private function subjectOf(Request $request): ?string
    {
        foreach ($request->route()->parameters() as $parameter) {
            if ($parameter instanceof Model) {
                return $this->describe($parameter);
            }
        }

        $sent = $request->input('title') ?? $request->input('name');

        return is_string($sent) && $sent !== '' ? $sent : null;
    }

    private function describe(Model $model): ?string
    {
        return match (true) {
            $model instanceof Student => trim(($model->user?->fullName ?? 'Unknown') . " ({$model->studentIDNumber})"),
            $model instanceof BookCopy => 'Accession no. ' . $model->accessionNumber . ($model->book?->title ? ' · ' . $model->book->title : ''),
            $model instanceof Loan => 'Loan #' . $model->getKey() . ($model->copy?->book?->title ? ' · ' . $model->copy->book->title : ''),
            isset($model->title) => (string) $model->title,
            isset($model->name) => (string) $model->name,
            default => class_basename($model) . ' #' . $model->getKey(),
        };
    }

    /** @return array<string, string> area key → label */
    public function areas(): array
    {
        return config('audit.areas');
    }

    public function index(?string $search, ?string $area, ?int $actorID, int $perPage = 20): LengthAwarePaginator
    {
        return AuditLog::query()
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('summary', 'like', "%{$search}%")
                ->orWhere('actorName', 'like', "%{$search}%")))
            ->when($area, fn ($q) => $q->where('action', 'like', $area . '.%'))
            ->when($actorID, fn ($q) => $q->where('actorID', $actorID))
            ->orderByDesc('logID')
            ->paginate($perPage);
    }
}
