<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

/** "Forgot password?" on the student side: an emailed, single-use link. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $overrides = []): User
    {
        $user = Student::factory()->approved()->create()->user;
        $user->forceFill(['password' => Hash::make('old-password-1')] + $overrides)->save();

        return $user;
    }

    private function tokenFor(User $user): string
    {
        return Password::createToken($user);
    }

    public function test_asking_mails_a_link_to_a_student_and_says_the_same_for_an_unknown_address(): void
    {
        Mail::fake();
        $user = $this->student();

        $known = $this->postJson('/api/password/forgot', ['email' => $user->email])->assertOk()->json('message');
        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        Mail::assertSent(ResetPasswordMail::class, 1);
        Mail::assertSent(ResetPasswordMail::class, fn ($mail) => $mail->hasTo($user->email) && str_contains($mail->resetUrl, '/reset-password?') && str_contains($mail->resetUrl, 'token='));
    }

    public function test_library_staff_and_disabled_accounts_get_no_link(): void
    {
        Mail::fake();

        $staff = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $staff->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        $disabled = $this->student(['disabledAt' => now()]);

        $this->postJson('/api/password/forgot', ['email' => $staff->email])->assertOk();
        $this->postJson('/api/password/forgot', ['email' => $disabled->email])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_the_link_sets_a_new_password_once_and_signs_out_everywhere(): void
    {
        $user = $this->student(['mustChangePassword' => true]);
        $user->createToken('somewhere-else');
        $token = $this->tokenFor($user);

        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));
        $this->assertFalse($user->mustChangePassword);
        $this->assertSame(0, $user->tokens()->count());

        // The link is single-use.
        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'another-pass-2', 'password_confirmation' => 'another-pass-2'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));

        // And the new password signs in.
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'brand-new-pass'])->assertOk();
    }

    public function test_a_wrong_link_or_a_weak_password_changes_nothing(): void
    {
        $user = $this->student();
        $token = $this->tokenFor($user);

        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => 'not-the-token', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertStatus(422);
        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'brand-new-pass', 'password_confirmation' => 'different-pass'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_a_link_that_has_expired_does_not_work(): void
    {
        $user = $this->student();
        $token = $this->tokenFor($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_page_can_ask_whether_a_link_is_still_usable(): void
    {
        $user = $this->student();
        $token = $this->tokenFor($user);

        $this->postJson('/api/password/check', ['email' => $user->email, 'token' => $token])->assertOk()->assertJson(['usable' => true]);
        $this->postJson('/api/password/check', ['email' => $user->email, 'token' => 'wrong'])->assertOk()->assertJson(['usable' => false]);

        // Asking changes nothing: the link still works afterwards, and is refused once it has been used.
        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertOk();
        $this->postJson('/api/password/check', ['email' => $user->email, 'token' => $token])->assertOk()->assertJson(['usable' => false]);
    }

    public function test_a_link_stops_working_when_the_password_changes_another_way(): void
    {
        $user = $this->student();
        $token = $this->tokenFor($user);
        $this->postJson('/api/password/check', ['email' => $user->email, 'token' => $token])->assertJson(['usable' => true]);

        // The password changes some other way (Profile, an administrator's reset): the emailed link is cancelled.
        $user->forceFill(['password' => Hash::make('changed-elsewhere-1')])->save();

        $this->postJson('/api/password/check', ['email' => $user->email, 'token' => $token])->assertJson(['usable' => false]);
        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $token, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
            ->assertStatus(422);
        $this->assertTrue(Hash::check('changed-elsewhere-1', $user->fresh()->password));
    }

    public function test_a_link_is_cancelled_when_the_email_changes(): void
    {
        $user = $this->student();
        $old = $user->email;
        $token = $this->tokenFor($user);

        $user->forceFill(['email' => 'new.address@davao.sti.edu.ph'])->save();

        $this->postJson('/api/password/check', ['email' => $old, 'token' => $token])->assertJson(['usable' => false]);
    }

    public function test_a_reset_lifts_a_sign_in_lock(): void
    {
        $user = $this->student();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'old-password-1'])->assertStatus(429);

        $this->postJson('/api/password/reset', ['email' => $user->email, 'token' => $this->tokenFor($user), 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertOk();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'brand-new-pass'])->assertOk();
    }
}
