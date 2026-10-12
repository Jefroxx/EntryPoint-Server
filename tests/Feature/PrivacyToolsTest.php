<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Models\Student;
use App\Models\User;
use App\Services\PrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Data privacy tools: find a member, export their data, anonymize their account on request.
 */
class PrivacyToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Student $member;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        $this->admin = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->admin->userID, 'uuid' => Str::uuid(), 'role' => 'admin']);

        $this->member = Student::factory()->approved()->create(['studentIDNumber' => '02-2024-777', 'academicProgram' => 'BS Nursing']);
        $this->member->user->update([
            'firstName' => 'Mia', 'middleInitial' => null, 'lastName' => 'Cruz', 'email' => 'mia.cruz@davao.sti.edu.ph',
            'phoneNumber' => '09171234567', 'address' => 'Davao City', 'birthDate' => '2004-03-04',
        ]);
    }

    private function loan(string $status, ?Student $student = null): Loan
    {
        $book = Book::factory()->create(['title' => 'Noli Me Tangere', 'areaOfLibrary' => 'circulation']);
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID, 'status' => $status === 'Returned' ? 'available' : 'borrowed']);

        return Loan::create([
            'uuid' => Str::uuid(), 'studentID' => ($student ?? $this->member)->studentID, 'copyID' => $copy->copyID, 'loanType' => 'circulation',
            'checkoutDate' => now()->subDays(10), 'dueDate' => now()->subDays(3), 'returnDate' => $status === 'Returned' ? now()->subDay() : null, 'status' => $status,
        ]);
    }

    private function fine(Loan $loan, string $status): Penalty
    {
        $type = PenaltyType::firstOrCreate(['category' => 'circulation'], ['uuid' => Str::uuid(), 'name' => 'Overdue (circulation)']);

        return Penalty::create([
            'uuid' => Str::uuid(), 'loanID' => $loan->loanID, 'penaltyTypeID' => $type->penaltyTypeID, 'amount' => 10,
            'computedAt' => now(), 'paymentStatus' => $status, 'settledAt' => $status === 'Paid' ? now() : null,
        ]);
    }

    public function test_only_administrators_use_the_privacy_tools(): void
    {
        $librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        foreach ([$librarian, $this->member->user] as $user) {
            $this->actingAs($user)->getJson('/api/admin/privacy/search?q=Mia')->assertForbidden();
            $this->actingAs($user)->getJson("/api/admin/privacy/members/{$this->member->uuid}/export")->assertForbidden();
            $this->actingAs($user)->postJson("/api/admin/privacy/members/{$this->member->uuid}/anonymize")->assertForbidden();
        }
    }

    public function test_a_member_is_found_by_name_email_or_id_and_the_screen_is_told_what_blocks_anonymizing(): void
    {
        $this->loan('Active');

        foreach (['Mia', 'cruz', 'mia.cruz@', '02-2024-777'] as $term) {
            $found = $this->actingAs($this->admin)->getJson('/api/admin/privacy/search?q=' . urlencode($term))->assertOk()->json('members');
            $this->assertSame([(string) $this->member->uuid], array_column($found, 'uuid'), "searching {$term}");
        }

        $found = $this->actingAs($this->admin)->getJson('/api/admin/privacy/search?q=Mia')->json('members.0');
        $this->assertSame('BS Nursing', $found['detail']);
        $this->assertSame(['1 book is still out or waiting to be checked.'], $found['blockers']);

        // One letter is not a search.
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/api/admin/privacy/search?q=m')->json('members'));
    }

    public function test_the_export_holds_what_the_library_keeps_about_them_and_is_logged_without_their_name(): void
    {
        $loan = $this->loan('Returned');
        $this->fine($loan, 'Paid');
        DB::table('attendance_logs')->insert(['uuid' => Str::uuid(), 'studentID' => $this->member->studentID, 'entryTime' => now()->subHours(2), 'exitTime' => now()]);

        $export = $this->actingAs($this->admin)->getJson("/api/admin/privacy/members/{$this->member->uuid}/export")->assertOk();

        $export->assertJsonPath('profile.name', $this->member->user->fullName)
            ->assertJsonPath('profile.email', 'mia.cruz@davao.sti.edu.ph')
            ->assertJsonPath('profile.phoneNumber', '09171234567')
            ->assertJsonPath('profile.birthDate', '2004-03-04')
            ->assertJsonPath('profile.idNumber', '02-2024-777')
            ->assertJsonPath('loans.0.book', 'Noli Me Tangere')
            ->assertJsonCount(1, 'fines')
            ->assertJsonCount(1, 'visits');

        $entry = DB::table('audit_logs')->where('action', 'privacy.exported')->first();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('Cruz', $entry->summary);
        $this->assertStringContainsString(PrivacyService::ref($this->member), $entry->summary);
    }

    public function test_an_account_with_a_book_out_an_unpaid_fine_or_an_open_reservation_cannot_be_anonymized_yet(): void
    {
        $out = $this->loan('Active');
        $this->actingAs($this->admin)->postJson("/api/admin/privacy/members/{$this->member->uuid}/anonymize")
            ->assertStatus(422)->assertJsonValidationErrors('account');

        $out->update(['status' => 'Returned', 'returnDate' => now()]);
        $this->fine($out, 'Unpaid');
        $this->actingAs($this->admin)->postJson("/api/admin/privacy/members/{$this->member->uuid}/anonymize")
            ->assertStatus(422)->assertJsonValidationErrors('account');

        // Nothing changed while blocked.
        $this->assertSame('Mia', $this->member->user->fresh()->firstName);
        $this->assertNull($this->member->user->fresh()->disabledAt);
    }

    public function test_anonymizing_removes_the_person_but_keeps_the_librarys_records(): void
    {
        $loan = $this->loan('Returned');
        $this->fine($loan, 'Paid');
        DB::table('attendance_logs')->insert(['uuid' => Str::uuid(), 'studentID' => $this->member->studentID, 'entryTime' => now()->subHours(2), 'exitTime' => now()]);
        DB::table('notifications')->insert(['uuid' => Str::uuid(), 'userID' => $this->member->user->userID, 'message' => 'Hello Mia', 'type' => 'general', 'sentAt' => now(), 'isRead' => false]);
        $token = $this->member->user->createToken('auth_token');

        // An earlier audit entry that named them.
        DB::table('audit_logs')->insert([
            'actorID' => $this->admin->userID, 'actorName' => 'Admin', 'actorRole' => 'admin', 'action' => 'student.approved',
            'summary' => 'Approved a student: ' . $this->member->user->fullName . ' (02-2024-777)',
        ]);

        $this->actingAs($this->admin)->postJson("/api/admin/privacy/members/{$this->member->uuid}/anonymize")->assertOk();

        $user = $this->member->user->fresh();
        $student = $this->member->fresh();
        $ref = PrivacyService::ref($this->member);

        $this->assertSame('Removed', $user->firstName);
        $this->assertSame('Member', $user->lastName);
        $this->assertSame("removed-{$ref}@removed.invalid", $user->email);
        $this->assertNull($user->phoneNumber);
        $this->assertNull($user->address);
        $this->assertNull($user->birthDate);
        $this->assertNotNull($user->disabledAt);
        $this->assertSame("REMOVED-{$ref}", $student->studentIDNumber);
        $this->assertNull($student->barcodeValue);
        $this->assertNull($student->academicProgram);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->userID)->count());
        $this->assertSame(0, DB::table('notifications')->where('userID', $user->userID)->count());

        // The library's own records stay, now about "Removed Member".
        $this->assertSame(1, Loan::where('studentID', $student->studentID)->count());
        $this->assertSame(1, DB::table('attendance_logs')->where('studentID', $student->studentID)->count());
        $this->assertSame(1, Penalty::where('loanID', $loan->loanID)->count());

        // The audit log no longer names them, and the anonymizing itself is logged by reference only.
        $approved = DB::table('audit_logs')->where('action', 'student.approved')->value('summary');
        $this->assertSame('Approved a student: a removed member (removed)', $approved);
        $this->assertSame("Anonymized a member account (ref {$ref})", DB::table('audit_logs')->where('action', 'privacy.anonymized')->value('summary'));
        $this->assertStringNotContainsString('Cruz', DB::table('audit_logs')->pluck('summary')->implode(' '));

        // They can no longer sign in, and the screen says so.
        $this->postJson('/api/login', ['email' => 'mia.cruz@davao.sti.edu.ph', 'password' => 'password123'])->assertStatus(422);
        $this->actingAs($this->admin)->postJson("/api/admin/privacy/members/{$this->member->uuid}/anonymize")->assertStatus(422);
        $found = $this->actingAs($this->admin)->getJson('/api/admin/privacy/search?q=Removed')->json('members.0');
        $this->assertTrue($found['isAnonymized']);
    }
}
