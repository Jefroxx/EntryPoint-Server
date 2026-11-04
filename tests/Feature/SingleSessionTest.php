<?php

namespace Tests\Feature;

use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** A student account is open in one place at a time: signing in again signs the earlier one out. */
class SingleSessionTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $user = $student->user;
        $user->update(['password' => Hash::make('secret-pass-1'), 'emailVerifiedAt' => now()]);

        return $user;
    }

    private function signIn(string $url, User $user): string
    {
        return $this->postJson($url, ['email' => $user->email, 'password' => 'secret-pass-1'])->assertOk()->json('access_token');
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_signing_in_again_signs_the_earlier_student_session_out(): void
    {
        $user = $this->student();

        $first = $this->signIn('/api/login', $user);
        $this->as($first)->getJson('/api/notifications')->assertOk();

        $second = $this->signIn('/api/login', $user);

        // The earlier sign-in (say, the incognito window) is rejected, and told why.
        $this->flushHeaders();
        $this->as($first)->getJson('/api/notifications')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'signed_in_elsewhere');

        // The new one works.
        $this->flushHeaders();
        $this->as($second)->getJson('/api/notifications')->assertOk();
    }

    public function test_the_quick_session_check_answers_ok_then_401_once_signed_in_elsewhere(): void
    {
        $user = $this->student();
        $first = $this->signIn('/api/login', $user);

        $this->as($first)->getJson('/api/session')->assertOk()->assertJsonPath('ok', true);

        $this->signIn('/api/login', $user);

        $this->flushHeaders();
        $this->as($first)->getJson('/api/session')->assertUnauthorized()->assertJsonPath('reason', 'signed_in_elsewhere');
    }

    public function test_an_ordinary_dead_token_is_just_unauthenticated(): void
    {
        $this->flushHeaders();
        $this->as('999|notarealtoken')->getJson('/api/notifications')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'unauthenticated');
    }

    public function test_librarians_can_stay_signed_in_on_a_desk_and_a_phone(): void
    {
        $user = User::factory()->create(['userType' => 'librarian', 'password' => Hash::make('secret-pass-1')]);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        $desk = $this->signIn('/api/librarian/login', $user);
        $phone = $this->signIn('/api/librarian/login', $user);

        $this->flushHeaders();
        $this->as($desk)->getJson('/api/notifications')->assertOk();
        $this->flushHeaders();
        $this->as($phone)->getJson('/api/notifications')->assertOk();
    }
}
