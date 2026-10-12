<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What the administrator does to accounts: staff (librarians and student assistants), faculty and staff members, and
 * the permissions of each role. Every change is written to the audit log.
 *
 * A new account gets a temporary password that is returned once, for the administrator to hand over.
 */
class AdminAccountService
{
    public const ROLE_LABELS = ['admin' => 'Administrator', 'librarian' => 'Librarian', 'assistant' => 'Student assistant'];

    public function __construct(
        private StaffPermissionService $permissions,
        private AuditLogService $audit,
    ) {
    }

    // ---------------------------------------------------------------- overview

    public function overview(): array
    {
        $staff = DB::table('librarians')->join('users', 'users.userID', '=', 'librarians.librarianID')
            ->selectRaw('librarians.role, COUNT(*) AS total, SUM(users.disabledAt IS NOT NULL) AS disabled')
            ->groupBy('librarians.role')->get()->keyBy('role');

        $members = DB::table('students')->join('users', 'users.userID', '=', 'students.studentID')
            ->where('students.registrationStatus', 'approved')
            ->selectRaw('students.memberType, COUNT(*) AS total, SUM(users.disabledAt IS NOT NULL) AS disabled')
            ->groupBy('students.memberType')->get()->keyBy('memberType');

        $count = fn ($rows, string $key, string $field = 'total') => (int) ($rows[$key]->{$field} ?? 0);

        return [
            'staff' => [
                'admin'     => $count($staff, 'admin'),
                'librarian' => $count($staff, 'librarian'),
                'assistant' => $count($staff, 'assistant'),
                'disabled'  => collect($staff)->sum(fn ($r) => (int) $r->disabled),
            ],
            'members' => [
                'student'  => $count($members, 'student'),
                'faculty'  => $count($members, 'faculty'),
                'staff'    => $count($members, 'staff'),
                'disabled' => collect($members)->sum(fn ($r) => (int) $r->disabled),
            ],
            'pendingRegistrations' => Student::where('registrationStatus', 'pending')->count(),
            'recentActivity'       => AuditLog::orderByDesc('logID')->limit(8)->get(),
        ];
    }

    // ---------------------------------------------------------------- staff accounts

    public function staff(?string $search, User $actor): array
    {
        return Librarian::with('user')
            ->join('users', 'users.userID', '=', 'librarians.librarianID')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('users.firstName', 'like', "%{$search}%")
                ->orWhere('users.lastName', 'like', "%{$search}%")
                ->orWhere('users.email', 'like', "%{$search}%")))
            ->orderBy('users.firstName')
            ->get('librarians.*')
            ->map(fn (Librarian $l) => $this->staffRow($l, $actor))
            ->all();
    }

    public function createStaff(User $actor, array $data): array
    {
        $password = $this->temporaryPassword();

        $librarian = DB::transaction(function () use ($data, $password) {
            $user = User::create([
                'uuid'            => Str::uuid(),
                'firstName'       => $data['firstName'],
                'middleInitial'   => $data['middleInitial'] ?? null,
                'lastName'        => $data['lastName'],
                'email'           => $data['email'],
                'password'        => Hash::make($password),
                'phoneNumber'     => $data['phoneNumber'] ?? null,
                'userType'        => 'librarian',
                'emailVerifiedAt' => now(),
                'mustChangePassword' => true,
            ]);

            return Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => $data['role']]);
        });

        $this->audit->record($actor, 'account.created', 'Created a ' . $this->roleLabel($data['role']) . " account: {$librarian->user->fullName}");

        return ['account' => $this->staffRow($librarian->load('user'), $actor), 'temporaryPassword' => $password];
    }

    public function updateStaff(User $actor, Librarian $librarian, array $data): array
    {
        $newRole = $data['role'] ?? $librarian->role;

        if ($newRole !== $librarian->role) {
            if ($librarian->librarianID === $actor->userID) {
                throw ValidationException::withMessages(['role' => ["You can't change your own role. Ask another administrator."]]);
            }
            $this->ensureAnotherAdminRemains($librarian, 'role');
        }

        DB::transaction(function () use ($librarian, $data, $newRole) {
            $librarian->user->update(collect($data)->only(['firstName', 'middleInitial', 'lastName', 'email', 'phoneNumber'])->all());
            $librarian->update(['role' => $newRole]);
        });

        $this->audit->record($actor, 'account.updated', "Updated the account of {$librarian->user->fullName}" . ($newRole !== $librarian->getOriginal('role') ? ' (now ' . $this->roleLabel($newRole) . ')' : ''));

        return $this->staffRow($librarian->fresh('user'), $actor);
    }

    private function staffRow(Librarian $librarian, User $actor): array
    {
        $user = $librarian->user;

        return [
            'uuid'          => $librarian->uuid,
            'firstName'     => $user->firstName,
            'middleInitial' => $user->middleInitial,
            'lastName'      => $user->lastName,
            'name'          => $user->fullName,
            'email'         => $user->email,
            'phoneNumber'   => $user->phoneNumber,
            'role'          => $librarian->role,
            'roleLabel'     => self::ROLE_LABELS[$librarian->role] ?? ucfirst($librarian->role),
            'isDisabled'    => $user->isDisabled(),
            'isYou'         => $user->userID === $actor->userID,
            'createdAt'     => $user->created_at?->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------------- faculty and staff members

    public function members(?string $search, ?string $type, int $perPage = 15): LengthAwarePaginator
    {
        return Student::with('user')
            ->join('users', 'users.userID', '=', 'students.studentID')
            ->where('students.memberType', '!=', 'student')
            ->when($type && $type !== 'all', fn ($q) => $q->where('students.memberType', $type))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w
                ->where('users.firstName', 'like', "%{$search}%")
                ->orWhere('users.lastName', 'like', "%{$search}%")
                ->orWhere('users.email', 'like', "%{$search}%")
                ->orWhere('students.studentIDNumber', 'like', "%{$search}%")
                ->orWhere('students.department', 'like', "%{$search}%")))
            ->orderBy('users.firstName')
            ->paginate($perPage, ['students.*'])
            ->through(fn (Student $s) => $this->memberRow($s));
    }

    public function createMember(User $actor, array $data): array
    {
        $password = $this->temporaryPassword();

        $student = DB::transaction(function () use ($data, $password) {
            $user = User::create([
                'uuid'            => Str::uuid(),
                'firstName'       => $data['firstName'],
                'middleInitial'   => $data['middleInitial'] ?? null,
                'lastName'        => $data['lastName'],
                'email'           => $data['email'],
                'password'        => Hash::make($password),
                'phoneNumber'     => $data['phoneNumber'] ?? null,
                'userType'        => 'student',
                // Made by the administrator, so there is no email to confirm and no registration to approve.
                'emailVerifiedAt' => now(),
                'mustChangePassword' => true,
            ]);

            return Student::create([
                'studentID'          => $user->userID,
                'uuid'               => Str::uuid(),
                'studentIDNumber'    => $data['employeeIDNumber'],
                'memberType'         => $data['memberType'],
                'department'         => $data['department'],
                'barcodeValue'       => 'STI-' . strtoupper(Str::random(10)),
                'registrationStatus' => 'approved',
                'reviewedAt'         => now(),
            ]);
        });

        $this->audit->record($actor, 'account.created', 'Created a ' . $data['memberType'] . " member: {$student->user->fullName}");

        return ['account' => $this->memberRow($student->load('user')), 'temporaryPassword' => $password];
    }

    public function updateMember(User $actor, Student $student, array $data): array
    {
        DB::transaction(function () use ($student, $data) {
            $student->user->update(collect($data)->only(['firstName', 'middleInitial', 'lastName', 'email', 'phoneNumber'])->all());
            $student->update(collect($data)->only(['memberType', 'department'])->all()
                + (isset($data['employeeIDNumber']) ? ['studentIDNumber' => $data['employeeIDNumber']] : []));
        });

        $this->audit->record($actor, 'account.updated', "Updated the account of {$student->user->fullName}");

        return $this->memberRow($student->fresh('user'));
    }

    private function memberRow(Student $student): array
    {
        $user = $student->user;

        return [
            'uuid'             => $student->uuid,
            'firstName'        => $user->firstName,
            'middleInitial'    => $user->middleInitial,
            'lastName'         => $user->lastName,
            'name'             => $user->fullName,
            'email'            => $user->email,
            'phoneNumber'      => $user->phoneNumber,
            'memberType'       => $student->memberType,
            'employeeIDNumber' => $student->studentIDNumber,
            'department'       => $student->department,
            'barcodeValue'     => $student->barcodeValue,
            'isDisabled'       => $user->isDisabled(),
            'createdAt'        => $user->created_at?->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------------- both kinds of account

    public function setDisabled(User $actor, User $target, bool $disabled): void
    {
        if ($disabled) {
            if ($target->userID === $actor->userID) {
                throw ValidationException::withMessages(['account' => ["You can't disable your own account."]]);
            }
            if ($target->librarian) {
                $this->ensureAnotherAdminRemains($target->librarian, 'account');
            }
        }

        $target->update(['disabledAt' => $disabled ? now() : null]);

        // Turning an account off ends any session it still has open.
        if ($disabled) {
            $target->tokens()->delete();
        }

        $this->audit->record($actor, $disabled ? 'account.disabled' : 'account.enabled', ($disabled ? 'Disabled' : 'Enabled') . " the account of {$target->fullName}");
    }

    /** A new temporary password, returned once. The account's open sessions end so only the new password works. */
    public function resetPassword(User $actor, User $target): string
    {
        $password = $this->temporaryPassword();

        $target->update(['password' => Hash::make($password), 'mustChangePassword' => true]);
        $target->tokens()->delete();

        $this->audit->record($actor, 'account.password-reset', "Reset the password of {$target->fullName}");

        return $password;
    }

    // ---------------------------------------------------------------- roles

    public function roles(): array
    {
        $matrix = $this->permissions->matrix();

        return [
            'permissions' => collect($this->permissions->descriptions())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'roles'       => collect(StaffPermissionService::ROLES)->map(fn ($role) => [
                'role'        => $role,
                'label'       => self::ROLE_LABELS[$role] ?? ucfirst($role),
                // The administrator always has everything.
                'editable'    => $role !== StaffPermissionService::ADMIN,
                'permissions' => $matrix[$role],
            ])->all(),
        ];
    }

    public function saveRole(User $actor, string $role, array $permissions): array
    {
        $this->permissions->set($role, $permissions);
        $this->audit->record($actor, 'roles.updated', 'Changed what the ' . $this->roleLabel($role) . ' role can do');

        return $this->roles();
    }

    // ---------------------------------------------------------------- helpers

    private function roleLabel(string $role): string
    {
        return strtolower(self::ROLE_LABELS[$role] ?? $role);
    }

    private function temporaryPassword(): string
    {
        return Str::password(10, letters: true, numbers: true, symbols: false);
    }

    /** The system always keeps at least one active administrator. */
    private function ensureAnotherAdminRemains(Librarian $librarian, string $field): void
    {
        if ($librarian->role !== StaffPermissionService::ADMIN) {
            return;
        }

        $others = Librarian::where('role', 'admin')
            ->where('librarianID', '!=', $librarian->librarianID)
            ->whereHas('user', fn ($q) => $q->whereNull('disabledAt'))
            ->count();

        if ($others === 0) {
            throw ValidationException::withMessages([$field => ['There must always be one active administrator.']]);
        }
    }
}
