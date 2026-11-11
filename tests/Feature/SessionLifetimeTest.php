<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/** Staff sign-ins expire sooner than a student's, and staff passwords must be longer. */
class SessionLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $user = User::factory()->create([
            'userType' => 'librarian', 'email' => "{$role}@stidavao.edu.ph",
            'password' => Hash::make('correct-horse-9'), 'emailVerifiedAt' => now(),
        ]);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $user;
    }

    public function test_staff_tokens_expire_and_an_admins_sooner(): void
    {
        Cache::flush();

        foreach (['librarian' => 60 * 24 * 3, 'admin' => 60 * 12] as $role => $minutes) {
            $this->staff($role);
            $this->postJson('/api/librarian/login', ['email' => "{$role}@stidavao.edu.ph", 'password' => 'correct-horse-9'])->assertOk();

            $token = PersonalAccessToken::latest('id')->first();
            $this->assertNotNull($token->expires_at, "{$role} token has no expiry");
            $this->assertEqualsWithDelta($minutes, now()->diffInMinutes($token->expires_at), 2);
        }
    }

    public function test_a_staff_password_needs_ten_characters(): void
    {
        $user = $this->staff('librarian');
        $this->actingAs($user, 'sanctum');

        $change = fn (string $new) => $this->putJson('/api/librarian/settings/password', [
            'currentPassword' => 'correct-horse-9', 'newPassword' => $new, 'newPassword_confirmation' => $new,
        ]);

        $change('nine-pw-9')->assertUnprocessable()->assertJsonValidationErrors('newPassword');
        $change('ten-chars-9')->assertSuccessful();
    }
}
