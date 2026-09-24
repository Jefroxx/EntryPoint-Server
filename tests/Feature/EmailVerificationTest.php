<?php

namespace Tests\Feature;

use App\Mail\StudentApproved;
use App\Mail\VerifyStudentEmail;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PORTAL = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['portal.url' => self::PORTAL, 'portal.student_email_domain' => '']);
    }

    private function register(string $email = 'ana@school.edu.ph'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/register', [
            'firstName'             => 'Ana',
            'lastName'              => 'Reyes',
            'email'                 => $email,
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'studentIDNumber'       => '2021-' . random_int(10000, 99999),
        ]);
    }

    /** The link exactly as it was mailed. */
    private function mailedLink(): string
    {
        $link = null;
        Mail::assertSent(VerifyStudentEmail::class, function (VerifyStudentEmail $mail) use (&$link) {
            $link = $mail->verifyUrl;

            return true;
        });

        return $link;
    }

    private function librarian(): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        return $user;
    }

    public function test_registering_mails_a_link_and_leaves_the_email_unconfirmed(): void
    {
        $this->librarian();

        $this->register()->assertCreated();

        Mail::assertSent(VerifyStudentEmail::class, fn ($mail) => $mail->hasTo('ana@school.edu.ph'));
        $this->assertNull(User::where('email', 'ana@school.edu.ph')->first()->emailVerifiedAt);
        // Librarians only hear about it once the email is confirmed.
        $this->assertSame(0, SystemNotification::where('type', 'new_registration')->count());
    }

    public function test_sign_in_is_refused_until_the_email_is_confirmed(): void
    {
        $this->register();

        $this->postJson('/api/login', ['email' => 'ana@school.edu.ph', 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.verification.0', 'required');
    }

    public function test_the_link_confirms_the_email_and_tells_librarians(): void
    {
        $this->librarian();
        $this->register();

        $this->get($this->mailedLink())->assertRedirect(self::PORTAL . '/login?verified=1');

        $this->assertNotNull(User::where('email', 'ana@school.edu.ph')->first()->emailVerifiedAt);
        $this->assertSame(1, SystemNotification::where('type', 'new_registration')->count());

        // Pending approval now, not pending confirmation.
        $this->postJson('/api/login', ['email' => 'ana@school.edu.ph', 'password' => 'password123'])
            ->assertStatus(422)
            ->assertJsonMissingPath('errors.verification')
            ->assertJsonPath('errors.email.0', 'Your account is still pending librarian approval.');
    }

    public function test_clicking_the_link_twice_is_harmless(): void
    {
        $this->librarian();
        $this->register();
        $link = $this->mailedLink();

        $this->get($link);
        $this->get($link)->assertRedirect(self::PORTAL . '/login?verified=already');

        $this->assertSame(1, SystemNotification::where('type', 'new_registration')->count());
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $this->register();
        $other = User::factory()->create(['emailVerifiedAt' => null]);

        // Swap in another account's uuid: the signature no longer matches.
        $link = preg_replace('#/email/verify/[^/]+/#', "/email/verify/{$other->uuid}/", $this->mailedLink());

        $this->get($link)->assertRedirect(self::PORTAL . '/login?verified=invalid');
        $this->assertNull($other->fresh()->emailVerifiedAt);
    }

    public function test_an_expired_link_says_so(): void
    {
        $this->register();
        $link = $this->mailedLink();

        $this->travel(config('portal.verify_link_minutes') + 1)->minutes();

        $this->get($link)->assertRedirect(self::PORTAL . '/login?verified=expired');
        $this->assertNull(User::where('email', 'ana@school.edu.ph')->first()->emailVerifiedAt);
    }

    public function test_resend_only_mails_unconfirmed_students_and_answers_the_same_either_way(): void
    {
        $this->register();
        Mail::fake(); // forget the registration email

        $known = $this->postJson('/api/email/resend', ['email' => 'ana@school.edu.ph'])->assertOk();
        $unknown = $this->postJson('/api/email/resend', ['email' => 'nobody@school.edu.ph'])->assertOk();

        Mail::assertSent(VerifyStudentEmail::class, 1);
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_a_librarian_cannot_approve_an_unconfirmed_student(): void
    {
        $librarian = $this->librarian();
        $this->register();
        $student = Student::first();

        $this->actingAs($librarian)->postJson("/api/librarian/students/{$student->studentID}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.student.0', "This student hasn't confirmed their email yet.");

        $this->get($this->mailedLink());

        $this->actingAs($librarian)->postJson("/api/librarian/students/{$student->studentID}/approve")->assertOk();
        Mail::assertSent(StudentApproved::class, fn ($mail) => $mail->hasTo('ana@school.edu.ph'));
    }

    public function test_the_school_domain_is_enforced_when_configured(): void
    {
        config(['portal.student_email_domain' => 'school.edu.ph']);

        $this->register('ana@gmail.com')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Use your school email (ending in @school.edu.ph).');

        $this->register('ana@school.edu.ph')->assertCreated();
    }

    public function test_the_verification_email_renders_with_the_link(): void
    {
        $user = User::factory()->make(['firstName' => 'Ana']);
        $html = (new VerifyStudentEmail($user, 'https://example.test/verify-me'))->render();

        $this->assertStringContainsString('Hi Ana', $html);
        $this->assertStringContainsString('https://example.test/verify-me', $html);
        $this->assertStringContainsString('cid:entrypoint-logo', $html);
    }
}
