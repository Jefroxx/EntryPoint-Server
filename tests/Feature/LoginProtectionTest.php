<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Librarian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Password guessing is stopped: repeated failures lock the sign-in for a few minutes, without telling a guesser anything. */
class LoginProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-9';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->staff = User::factory()->create([
            'userType'        => 'librarian',
            'email'           => 'desk@stidavao.edu.ph',
            'password'        => Hash::make(self::PASSWORD),
            'emailVerifiedAt' => now(),
        ]);
        Librarian::create(['librarianID' => $this->staff->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function signIn(string $login, string $password)
    {
        return $this->postJson('/api/librarian/login', ['email' => $login, 'password' => $password]);
    }

    private function failTimes(int $times, string $login = 'desk@stidavao.edu.ph'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->signIn($login, 'wrong-guess')->assertUnprocessable();
        }
    }

    public function test_five_wrong_passwords_lock_the_account_even_for_the_right_one(): void
    {
        $this->failTimes(5);

        // The sixth try is refused outright, with a plain reason in the usual place and a 429.
        $this->signIn('desk@stidavao.edu.ph', 'wrong-guess')
            ->assertStatus(429)
            ->assertJsonPath('errors.email.0', fn ($message) => str_contains($message, 'Too many sign-in attempts'));

        // ...and so is the CORRECT password: a guesser who finally hits it learns nothing from the lock.
        $this->signIn('desk@stidavao.edu.ph', self::PASSWORD)->assertStatus(429);
    }

    public function test_the_lock_ends_after_the_window(): void
    {
        $this->failTimes(5);
        $this->signIn('desk@stidavao.edu.ph', self::PASSWORD)->assertStatus(429);

        $this->travel(11)->minutes();

        $this->signIn('desk@stidavao.edu.ph', self::PASSWORD)->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_a_correct_sign_in_forgets_earlier_mistakes(): void
    {
        $this->failTimes(4);
        $this->signIn('desk@stidavao.edu.ph', self::PASSWORD)->assertOk();

        // Four more mistakes are fine, because the counter started over; the account is not locked.
        $this->failTimes(4);
        $this->signIn('desk@stidavao.edu.ph', self::PASSWORD)->assertOk();
    }

    public function test_a_lock_is_about_one_account_and_other_accounts_still_sign_in(): void
    {
        $this->failTimes(5);

        $other = User::factory()->create([
            'userType' => 'librarian', 'email' => 'other@stidavao.edu.ph', 'password' => Hash::make(self::PASSWORD), 'emailVerifiedAt' => now(),
        ]);
        Librarian::create(['librarianID' => $other->userID, 'uuid' => Str::uuid(), 'role' => 'assistant']);

        $this->signIn('other@stidavao.edu.ph', self::PASSWORD)->assertOk();
    }

    public function test_an_account_that_does_not_exist_is_locked_the_same_way(): void
    {
        // So the lock never reveals which accounts are real.
        $this->failTimes(5, 'nobody@stidavao.edu.ph');

        $this->signIn('nobody@stidavao.edu.ph', 'anything')->assertStatus(429);
    }

    public function test_a_wrong_account_and_a_wrong_password_look_identical(): void
    {
        $wrongPassword = $this->signIn('desk@stidavao.edu.ph', 'wrong-guess');
        $wrongAccount = $this->signIn('nobody@stidavao.edu.ph', 'wrong-guess');

        $this->assertSame($wrongPassword->json('errors.email.0'), $wrongAccount->json('errors.email.0'));
        $this->assertSame($wrongPassword->status(), $wrongAccount->status());
    }

    public function test_the_lock_is_written_to_the_audit_log_once(): void
    {
        $this->failTimes(5);
        $this->signIn('desk@stidavao.edu.ph', 'wrong-guess')->assertStatus(429);
        $this->signIn('desk@stidavao.edu.ph', 'wrong-guess')->assertStatus(429);

        $entries = AuditLog::where('action', 'security.login_locked')->get();

        $this->assertCount(1, $entries);
        $this->assertSame('system', $entries->first()->actorRole);
        $this->assertStringContainsString('desk@stidavao.edu.ph', $entries->first()->summary);
    }

    public function test_an_enormous_password_is_refused_before_it_is_hashed(): void
    {
        $this->signIn('desk@stidavao.edu.ph', str_repeat('a', 300))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_many_people_signing_in_through_one_shared_address_are_not_locked_out_by_each_other(): void
    {
        // The hosted site's proxy makes every visitor look like one address. A class of students registering, or one
        // person's wrong guesses on THEIR account, must not stop everyone else.
        for ($i = 1; $i <= 30; $i++) {
            $this->signIn("student{$i}@stidavao.edu.ph", 'wrong-guess')->assertUnprocessable();
        }

        $this->signIn('desk@stidavao.edu.ph', self::PASSWORD)->assertOk();

        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }
    }
}
