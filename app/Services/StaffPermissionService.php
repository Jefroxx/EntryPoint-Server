<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What each staff role may do. The administrator always has everything; the other roles use the list the
 * administrator saved (role_permissions), or the defaults in config/permissions.php until then.
 */
class StaffPermissionService
{
    public const ADMIN = 'admin';

    /** The roles an account can have, in the order the admin side lists them. */
    public const ROLES = ['admin', 'librarian', 'assistant'];

    /** @return string[] */
    public function keys(): array
    {
        return array_keys(config('permissions.keys'));
    }

    /** Key → what it covers, for the roles screen. */
    public function descriptions(): array
    {
        return config('permissions.keys');
    }

    /** The role of a staff account; an account without a librarian row counts as a plain librarian. */
    public function roleOf(User $user): string
    {
        $role = $user->librarian?->role ?? 'librarian';

        return in_array($role, self::ROLES, true) ? $role : 'librarian';
    }

    /** @return string[] */
    public function forRole(string $role): array
    {
        if ($role === self::ADMIN) {
            return $this->keys();
        }

        $row = DB::table('role_permissions')->where('role', $role)->first();
        $list = $row ? (json_decode($row->permissions, true) ?: []) : (config('permissions.defaults')[$role] ?? []);

        // Keep the config's order, and drop anything that is no longer a real permission.
        return array_values(array_intersect($this->keys(), $list));
    }

    /** @return string[] */
    public function forUser(User $user): array
    {
        return $this->forRole($this->roleOf($user));
    }    /** The signed-in staff account's role and what it may do: what the login and /librarian/me send to the screens. */
    public function describe(User $user): array
    {
        $role = $this->roleOf($user);

        return [
            'role'        => $role,
            'isAdmin'     => $role === self::ADMIN,
            'permissions' => $this->forRole($role),
        ];
    }

    /** Every role with its permissions, for the roles screen. */
    public function matrix(): array
    {
        $out = [];
        foreach (self::ROLES as $role) {
            $out[$role] = $this->forRole($role);
        }

        return $out;
    }

    /**
     * Save a role's permissions. The administrator can't be limited.
     *
     * @param string[] $permissions
     */
    public function set(string $role, array $permissions): void
    {
        if ($role === self::ADMIN || ! in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('That role can\'t be changed.');
        }

        $clean = array_values(array_intersect($this->keys(), $permissions));

        DB::table('role_permissions')->updateOrInsert(
            ['role' => $role],
            ['permissions' => json_encode($clean), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** Whether the account has ANY of these permissions. */
    public function allows(User $user, array $anyOf): bool
    {
        return count(array_intersect($this->forUser($user), $anyOf)) > 0;
    }

    /**
     * The permissions a request needs (any one of them), or null when its area is open to every staff account.
     *
     * @return string[]|null
     */
    public function requiredFor(Request $request): ?array
    {
        // /api/librarian/{section}/{part}: a rule for the two segments wins over one for the section alone.
        $section = $request->segment(3);
        $rule = config('permissions.routes.' . $section . '/' . $request->segment(4)) ?? config('permissions.routes.' . $section);

        if (! $rule) {
            return null;
        }

        return $request->isMethodSafe() ? ($rule['read'] ?? $rule['write']) : $rule['write'];
    }
}
