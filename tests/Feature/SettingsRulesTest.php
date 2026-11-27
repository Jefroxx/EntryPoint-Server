<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\LoanPeriod;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The librarian Settings page: who may change the library-wide rules, what a change does, and the account safeguards. */
class SettingsRulesTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $user = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $user;
    }

    private function reserve(Student $student, string $reservedAt, string $status = 'Waiting'): Reservation
    {
        $book = Book::factory()->create(['areaOfLibrary' => 'circulation']);

        return Reservation::create([
            'uuid' => Str::uuid(), 'studentID' => $student->studentID, 'bookID' => $book->bookID,
            'status' => $status, 'reservedAt' => $reservedAt,
            'acceptedAt' => $status === 'Accepted' ? $reservedAt : null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
    }

    // ---------------------------------------------------------------- who may change what

    public function test_only_staff_with_the_settings_permission_can_change_borrowing_and_rewards(): void
    {
        $assistant = $this->staff('assistant');

        $this->actingAs($assistant)->putJson('/api/librarian/settings/borrowing', ['holdHours' => 24, 'maxBooks' => 9])->assertForbidden();
        $this->actingAs($assistant)->getJson('/api/librarian/settings/borrowing/impact?maxBooks=1')->assertForbidden();
        $this->actingAs($assistant)->putJson('/api/librarian/settings/rewards', ['lowStockAt' => 50])->assertForbidden();
        $this->assertSame(3, \App\Services\AppSettings::maxBooks());

        $this->actingAs($this->staff('librarian'))->putJson('/api/librarian/settings/borrowing', ['holdHours' => 24, 'maxBooks' => 5])->assertOk();
        $this->assertSame(5, \App\Services\AppSettings::maxBooks());
    }

    public function test_borrowing_and_rewards_changes_are_written_to_the_audit_log(): void
    {
        $librarian = $this->staff('librarian');

        $this->actingAs($librarian)->putJson('/api/librarian/settings/borrowing', ['holdHours' => 24, 'maxBooks' => 4])->assertOk();
        $this->actingAs($librarian)->putJson('/api/librarian/settings/rewards', ['lowStockAt' => 2])->assertOk();

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'settings.borrowing')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'settings.rewards')->count());
    }

    // ---------------------------------------------------------------- fine rules by area

    public function test_a_fine_rule_can_be_set_for_any_area_of_the_library_and_only_those(): void
    {
        $librarian = $this->staff('librarian');
        $payload = ['rate' => 2.5, 'rateUnit' => 'day', 'gracePeriodDays' => 1];

        $before = \App\Models\PenaltyType::count();
        $this->actingAs($librarian)->putJson('/api/librarian/settings/fine-rules/not-an-area', $payload)->assertUnprocessable()->assertJsonValidationErrors('area');
        $this->assertSame($before, \App\Models\PenaltyType::count(), 'nothing is created for an unknown area');

        LoanPeriod::create(['area' => 'theses', 'name' => 'Theses', 'acronym' => 'THS', 'loanable' => true, 'periodValue' => 1, 'periodUnit' => 'days']);
        $this->actingAs($librarian)->putJson('/api/librarian/settings/fine-rules/theses', $payload)->assertOk();

        $rules = collect($this->actingAs($librarian)->getJson('/api/librarian/settings')->json('fineRules'));
        $theses = $rules->firstWhere('area', 'theses');
        $this->assertTrue($theses['configured']);
        $this->assertSame('Theses', $theses['areaName']);
        $this->assertSame(2.5, $theses['rate']);
        $this->assertFalse($rules->firstWhere('area', 'circulation')['configured']);
    }

    public function test_a_fine_rate_is_kept_to_sensible_money(): void
    {
        $librarian = $this->staff('librarian');

        foreach ([['rate' => 1.255], ['rate' => 10001], ['rate' => -1], ['gracePeriodDays' => 31]] as $bad) {
            $this->actingAs($librarian)->putJson('/api/librarian/settings/fine-rules/circulation', $bad + ['rate' => 1, 'rateUnit' => 'day', 'gracePeriodDays' => 0])->assertUnprocessable();
        }
    }

    // ---------------------------------------------------------------- lowering the borrowing limit

    public function test_lowering_the_limit_cancels_the_newest_reservations_of_students_over_it(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $first = $this->reserve($student, now()->subDays(3));
        $second = $this->reserve($student, now()->subDays(2), 'Accepted');
        $third = $this->reserve($student, now()->subDay());
        $fine = $this->reserve($other, now()->subDay());

        $librarian = $this->staff('librarian');

        // Asking first changes nothing.
        $this->actingAs($librarian)->getJson('/api/librarian/settings/borrowing/impact?maxBooks=2')
            ->assertOk()->assertJson(['students' => 1, 'reservations' => 1]);
        $this->assertSame('Waiting', $third->fresh()->status);

        $this->putJson('/api/librarian/settings/borrowing', ['holdHours' => 24, 'maxBooks' => 2])
            ->assertOk()->assertJson(['cancelled' => 1, 'students' => 1]);

        $this->assertSame('Waiting', $first->fresh()->status);
        $this->assertSame('Accepted', $second->fresh()->status);
        $this->assertSame('Rejected', $third->fresh()->status, 'the newest one goes');
        $this->assertSame('Waiting', $fine->fresh()->status, 'a student within the limit is untouched');
        $this->assertSame(1, SystemNotification::where('userID', $student->studentID)->where('type', 'reservation_cancelled')->count());
    }

    public function test_books_already_on_loan_use_up_the_new_limit_first(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        Loan::create([
            'uuid' => Str::uuid(), 'studentID' => $student->studentID,
            'copyID' => BookCopy::factory()->create(['bookID' => Book::factory()->create(['areaOfLibrary' => 'circulation'])->bookID, 'status' => 'borrowed'])->copyID,
            'loanType' => 'circulation', 'checkoutDate' => now()->subDay(), 'dueDate' => now()->addDays(2), 'status' => 'Active',
        ]);
        $older = $this->reserve($student, now()->subDays(2));
        $newer = $this->reserve($student, now()->subDay());

        // Limit 2 with one book out leaves room for one reservation: the newer one goes.
        $this->actingAs($this->staff('librarian'))->putJson('/api/librarian/settings/borrowing', ['holdHours' => 24, 'maxBooks' => 2])->assertOk()->assertJson(['cancelled' => 1]);
        $this->assertSame('Waiting', $older->fresh()->status);
        $this->assertSame('Rejected', $newer->fresh()->status);
        $this->assertSame(1, Loan::where('studentID', $student->studentID)->where('status', 'Active')->count(), 'the loan itself is never touched');
    }

    public function test_raising_the_limit_cancels_nothing(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->reserve($student, now()->subDay());

        $this->actingAs($this->staff('librarian'))->putJson('/api/librarian/settings/borrowing', ['holdHours' => 24, 'maxBooks' => 6])->assertOk()->assertJson(['cancelled' => 0]);
        $this->assertSame(1, Reservation::where('status', 'Waiting')->count());
    }

    // ---------------------------------------------------------------- the account

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $librarian = $this->staff('librarian');
        $base = ['firstName' => 'Lia', 'lastName' => 'Cruz'];

        $this->actingAs($librarian)->patchJson('/api/librarian/settings/account', $base + ['email' => 'new@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('currentPassword');
        $this->actingAs($librarian)->patchJson('/api/librarian/settings/account', $base + ['email' => 'new@example.com', 'currentPassword' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('currentPassword');
        $this->assertNotSame('new@example.com', $librarian->fresh()->email);

        // The factory's password, and a name-only change (same email) needs no password at all.
        $this->actingAs($librarian)->patchJson('/api/librarian/settings/account', $base + ['email' => $librarian->email])->assertOk();
        $this->actingAs($librarian)->patchJson('/api/librarian/settings/account', $base + ['email' => 'new@example.com', 'currentPassword' => 'password'])->assertOk();
        $this->assertSame('new@example.com', $librarian->fresh()->email);
    }

    public function test_changing_the_password_signs_out_the_other_devices_and_is_rate_limited(): void
    {
        $librarian = $this->staff('librarian');
        $here = $librarian->createToken('here');
        $elsewhere = $librarian->createToken('elsewhere');

        $this->withToken($here->plainTextToken)->putJson('/api/librarian/settings/password', [
            'currentPassword' => 'password', 'newPassword' => 'a-much-longer-passphrase', 'newPassword_confirmation' => 'a-much-longer-passphrase',
        ])->assertOk();

        $this->assertNotNull($librarian->tokens()->find($here->accessToken->id), 'this device stays signed in');
        $this->assertNull($librarian->tokens()->find($elsewhere->accessToken->id), 'the other device is signed out');

        // A guessing run against the current-password check is stopped.
        $last = null;
        for ($i = 0; $i < 8; $i++) {
            $last = $this->withToken($here->plainTextToken)->putJson('/api/librarian/settings/password', ['currentPassword' => 'nope']);
        }
        $this->assertSame(429, $last->getStatusCode());
    }
}
