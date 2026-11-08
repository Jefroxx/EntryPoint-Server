<?php

namespace Tests\Feature;

use App\Mail\VerifyStudentEmail;
use App\Models\AttendanceLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\LoanPeriod;
use App\Models\MarketItem;
use App\Models\PenaltyRule;
use App\Models\PenaltyType;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The test cases of the capstone paper (Testing chapter), run against the real API. Each case sends a real request and
 * records what the system answered; the paper's "Actual Result" column is copied from storage/app/paper-tests.json, so it
 * is what happened and not what was hoped for. Run: php artisan test --filter=PaperTestCasesTest
 */
class PaperTestCasesTest extends TestCase
{
    use RefreshDatabase;

    private static array $rows = [];

    private User $librarian;
    private User $assistant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['portal.student_email_domain' => 'davao.sti.edu.ph']);
        BookSubject::factory()->create(['name' => 'Science', 'classificationCode' => '500']);
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        LoanPeriod::updateOrCreate(['area' => 'thesis'], ['loanable' => false, 'periodValue' => null, 'periodUnit' => 'days']);

        $this->librarian = $this->staff('librarian');
        $this->assistant = $this->staff('assistant');
        $this->admin = $this->staff('admin');
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2) . '/storage/app/paper-tests.json', json_encode(self::$rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        parent::tearDownAfterClass();
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    private function staff(string $role): User
    {
        $user = User::factory()->create(['userType' => 'librarian', 'password' => bcrypt('password123')]);
        Librarian::create(['librarianID' => $user->userID, 'uuid' => Str::uuid(), 'role' => $role]);

        return $user;
    }

    private function member(array $overrides = []): Student
    {
        return Student::factory()->create(array_merge(['registrationStatus' => 'approved', 'barcodeValue' => 'STI-' . Str::upper(Str::random(8))], $overrides));
    }

    private function book(string $area = 'circulation'): Book
    {
        $book = Book::factory()->create(['areaOfLibrary' => $area]);
        BookCopy::factory()->create(['bookID' => $book->bookID]);

        return $book;
    }

    private function signedOut(): void
    {
        app('auth')->forgetGuards();
    }

    /** What the system said, in one line: the status and the first message it gave. */
    private function said(TestResponse $r): string
    {
        $json = $r->json();
        $msg = null;
        if (is_array($json)) {
            $errors = $json['errors'] ?? null;
            $msg = is_array($errors) ? (collect($errors)->flatten()->first()) : ($json['message'] ?? null);
        }

        return 'HTTP ' . $r->getStatusCode() . ($msg ? ': ' . $msg : '');
    }

    private function rec(string $id, string $module, string $scenario, string $expected, string $actual, bool $pass): void
    {
        self::$rows[] = compact('id', 'module', 'scenario', 'expected', 'actual', 'pass');
        $this->assertTrue($pass, "$id failed: $actual");
    }

    /** Records one case from a response and a pass condition on its status. */
    private function caseOf(string $id, string $module, string $scenario, string $expected, TestResponse $r, int|array $statuses, ?string $contains = null): void
    {
        $statuses = (array) $statuses;
        $said = $this->said($r);
        $ok = in_array($r->getStatusCode(), $statuses, true) && ($contains === null || str_contains($said, $contains));
        $this->rec($id, $module, $scenario, $expected, $said, $ok);
    }

    // ---- 1. Login ----------------------------------------------------------------------------------------------

    public function test_login(): void
    {
        $m = 'Login';
        $student = $this->member(['registrationStatus' => 'approved']);
        $email = $student->user->email;

        $this->caseOf('LTC1', $m, 'Sign in with an email that has no account', 'Refuse the sign-in with an "incorrect credentials" message', $this->postJson('/api/login', ['email' => 'nobody@davao.sti.edu.ph', 'password' => 'password123']), 422, 'incorrect');
        $this->caseOf('LTC2', $m, 'Sign in with the right email and a wrong password', 'Refuse the sign-in with an "incorrect credentials" message', $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong-password']), 422, 'incorrect');
        $this->caseOf('LTC3', $m, 'Leave both email and password empty', 'Refuse the request and ask for both fields', $this->postJson('/api/login', ['email' => '', 'password' => '']), 422, 'required');
        $this->caseOf('LTC4', $m, 'Enter an email without "@"', 'Refuse the request and ask for a valid email', $this->postJson('/api/login', ['email' => 'juan.delacruz', 'password' => 'password123']), 422, 'valid email');

        $unverified = $this->member();
        $unverified->user->update(['emailVerifiedAt' => null]);
        $this->caseOf('LTC5', $m, 'Sign in before the email address is confirmed', 'Refuse the sign-in and tell the member to confirm the email', $this->postJson('/api/login', ['email' => $unverified->user->email, 'password' => 'password123']), 422, 'Confirm your email');

        $pending = $this->member(['registrationStatus' => 'pending']);
        $this->caseOf('LTC6', $m, 'Sign in while the registration is still pending', 'Refuse the sign-in: waiting for librarian approval', $this->postJson('/api/login', ['email' => $pending->user->email, 'password' => 'password123']), 422, 'pending');

        $rejected = $this->member(['registrationStatus' => 'rejected']);
        $this->caseOf('LTC7', $m, 'Sign in after the registration was rejected', 'Refuse the sign-in: registration rejected', $this->postJson('/api/login', ['email' => $rejected->user->email, 'password' => 'password123']), 422, 'rejected');

        $disabled = $this->member();
        $disabled->user->update(['disabledAt' => now()]);
        $this->caseOf('LTC8', $m, 'Sign in with an account an administrator disabled', 'Refuse the sign-in: account disabled', $this->postJson('/api/login', ['email' => $disabled->user->email, 'password' => 'password123']), 422, 'disabled');

        $r = $this->postJson('/api/login', ['email' => $email, 'password' => 'password123']);
        $this->rec('LTC9', $m, 'Sign in with an approved member\'s correct credentials', 'Sign in and return an access token', $this->said($r) . ($r->json('access_token') ? ' (token returned)' : ''), $r->getStatusCode() === 200 && (bool) $r->json('access_token'));

        $r = $this->postJson('/api/librarian/login', ['email' => $this->librarian->email, 'password' => 'password123']);
        $this->rec('LTC10', $m, 'Sign in at the staff portal with a librarian\'s correct credentials', 'Sign in and return an access token', $this->said($r) . ($r->json('access_token') ? ' (token returned)' : ''), $r->getStatusCode() === 200 && (bool) $r->json('access_token'));

        $this->caseOf('LTC11', $m, 'Sign in at the staff portal with a member\'s account', 'Refuse the sign-in and point to the student portal', $this->postJson('/api/librarian/login', ['email' => $email, 'password' => 'password123']), 422, 'student portal');
        $this->caseOf('LTC12', $m, 'Sign in at the student portal with a librarian\'s account', 'Refuse the sign-in and point to the librarian portal', $this->postJson('/api/login', ['email' => $this->librarian->email, 'password' => 'password123']), 422, 'librarian portal');

        $this->signedOut();
        $this->caseOf('LTC13', $m, 'Open a staff page without signing in', 'Refuse the request: not authenticated', $this->getJson('/api/librarian/loans'), 401);

        $this->signedOut();
        $this->caseOf('LTC14', $m, 'Open a staff page with a member\'s account', 'Refuse the request: forbidden', $this->actingAs($student->user)->getJson('/api/librarian/loans'), [401, 403]);

        $this->signedOut();
        $token = $this->postJson('/api/login', ['email' => $email, 'password' => 'password123'])->json('access_token');
        $this->signedOut();
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->signedOut();
        $this->caseOf('LTC15', $m, 'Use the access token again after signing out', 'The token no longer works', $this->withToken($token)->getJson('/api/student/profile'), 401);

        $this->signedOut();
        $first = $this->postJson('/api/login', ['email' => $email, 'password' => 'password123'])->json('access_token');
        $this->signedOut();
        $this->postJson('/api/login', ['email' => $email, 'password' => 'password123'])->assertOk();
        $this->signedOut();
        $this->caseOf('LTC16', $m, 'Sign in a second time while a first sign-in is still open', 'The first sign-in is ended: one open session per member', $this->withToken($first)->getJson('/api/student/profile'), 401);
    }

    // ---- 2. Registration ---------------------------------------------------------------------------------------

    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/register', array_merge([
            'firstName' => 'Ana', 'lastName' => 'Reyes', 'email' => 'ana.reyes@davao.sti.edu.ph',
            'password' => 'password123', 'password_confirmation' => 'password123', 'memberIDNumber' => '02-2026-12345',
            'academicProgram' => 'BS Information Technology',
        ], $overrides));
    }

    public function test_registration(): void
    {
        $m = 'Registration';
        $this->caseOf('RTC1', $m, 'Leave the first name empty', 'Refuse the registration and ask for the first name', $this->register(['firstName' => '']), 422, 'first name');
        $this->caseOf('RTC2', $m, 'Leave the last name empty', 'Refuse the registration and ask for the last name', $this->register(['lastName' => '']), 422, 'last name');
        $this->caseOf('RTC3', $m, 'Leave the ID number empty', 'Refuse the registration and ask for the ID number', $this->register(['memberIDNumber' => '']), 422, 'required');
        $this->caseOf('RTC4', $m, 'Enter an email that is not a valid address', 'Refuse the registration and ask for a valid email', $this->register(['email' => 'ana.reyes']), 422, 'valid email');
        $this->caseOf('RTC5', $m, 'Enter an email outside the school domain', 'Refuse the registration: use the school email', $this->register(['email' => 'ana@gmail.com']), 422, 'school email');
        $this->caseOf('RTC6', $m, 'Enter a password shorter than 8 characters', 'Refuse the registration and ask for a longer password', $this->register(['password' => 'short', 'password_confirmation' => 'short']), 422, '8 characters');
        $this->caseOf('RTC7', $m, 'Enter a confirmation that differs from the password', 'Refuse the registration: confirmation does not match', $this->register(['password_confirmation' => 'different123']), 422, 'does not match');
        $this->caseOf('RTC8', $m, 'Leave the middle initial and the program out (optional fields)', 'Accept the registration', $this->register(['middleInitial' => null, 'academicProgram' => null, 'email' => 'ben.cruz@davao.sti.edu.ph', 'memberIDNumber' => '02-2026-55555']), [200, 201]);

        $r = $this->register(['email' => 'carl.diaz@davao.sti.edu.ph', 'memberIDNumber' => '02-2026-77777']);
        $user = User::where('email', 'carl.diaz@davao.sti.edu.ph')->first();
        $sent = false;
        try { Mail::assertSent(VerifyStudentEmail::class, fn ($mail) => $mail->hasTo('carl.diaz@davao.sti.edu.ph')); $sent = true; } catch (\Throwable) {}
        $this->rec('RTC9', $m, 'Register with complete and valid details', 'Create the account as pending, without confirmed email, and email a verification link',
            $this->said($r) . '; account ' . ($user ? 'created' : 'missing') . ($user && ! $user->emailVerifiedAt ? ', email not yet confirmed' : '') . '; verification email ' . ($sent ? 'sent' : 'not sent'),
            in_array($r->getStatusCode(), [200, 201], true) && $user && ! $user->emailVerifiedAt && $sent);

        $this->caseOf('RTC10', $m, 'Register again with the same email', 'Refuse the registration: email already taken', $this->register(['email' => 'carl.diaz@davao.sti.edu.ph', 'memberIDNumber' => '02-2026-99999']), 422, 'taken');
        $this->caseOf('RTC11', $m, 'Register with an ID number that is already used', 'Refuse the registration: ID number already taken', $this->register(['email' => 'dina.lee@davao.sti.edu.ph', 'memberIDNumber' => '02-2026-77777']), 422, 'taken');

        // The member confirms the email with the link, then a librarian approves, and only then can the member sign in.
        $link = null;
        Mail::assertSent(VerifyStudentEmail::class, function ($mail) use (&$link) { if ($mail->hasTo('carl.diaz@davao.sti.edu.ph')) { $link = $mail->verifyUrl; } return true; });
        $this->signedOut();
        $r = $this->get($link);
        $confirmed = (bool) $user->fresh()->emailVerifiedAt;
        $told = SystemNotification::where('userID', $this->librarian->userID)->where('type', 'new_registration')->exists();
        $this->rec('RTC12', $m, 'The member opens the verification link from the email', 'The email is marked as confirmed and the librarians are told about the new registration',
            'HTTP ' . $r->getStatusCode() . ' (redirected to the sign-in page); email confirmed: ' . ($confirmed ? 'yes' : 'no') . '; librarians notified: ' . ($told ? 'yes' : 'no'), $confirmed && $told);

        $this->signedOut();
        $this->caseOf('RTC13', $m, 'Sign in after confirming the email but before a librarian approves', 'Refuse the sign-in: waiting for approval', $this->postJson('/api/login', ['email' => 'carl.diaz@davao.sti.edu.ph', 'password' => 'password123']), 422, 'pending');

        $student = $user->student;
        $this->signedOut();
        $r = $this->actingAs($this->librarian)->postJson("/api/librarian/students/{$student->uuid}/approve");
        $this->rec('RTC14', $m, 'A librarian approves the registration', 'The registration status becomes approved', $this->said($r) . '; registration status: ' . $student->fresh()->registrationStatus, $student->fresh()->registrationStatus === 'approved');

        $this->signedOut();
        $r = $this->postJson('/api/login', ['email' => 'carl.diaz@davao.sti.edu.ph', 'password' => 'password123']);
        $this->rec('RTC15', $m, 'Sign in after the approval', 'The member signs in', $this->said($r), $r->getStatusCode() === 200 && (bool) $r->json('access_token'));
    }

    // ---- 3. Attendance -----------------------------------------------------------------------------------------

    public function test_attendance(): void
    {
        $m = 'Attendance';
        $student = $this->member(['barcodeValue' => 'STI-ATT-0001']);
        $scan = fn (string $code) => $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/scan', ['barcodeValue' => $code]);

        $this->caseOf('ATC1', $m, 'Scan a barcode that belongs to nobody', 'Refuse the scan: barcode not recognized', $scan('NOT-A-REAL-CODE'), 422, 'not recognized');

        $pending = $this->member(['registrationStatus' => 'pending', 'barcodeValue' => 'STI-ATT-0002']);
        $this->caseOf('ATC2', $m, 'Scan the ID of a member whose registration is not approved', 'Refuse the scan: account not approved', $scan('STI-ATT-0002'), 422, 'not approved');

        $r = $scan('STI-ATT-0001');
        $this->rec('ATC3', $m, 'Scan an approved member\'s ID (first scan)', 'Open a visit: check-in', $this->said($r) . ' (action: ' . $r->json('action') . ')', $r->isSuccessful() && $r->json('action') === 'check_in');

        $this->caseOf('ATC4', $m, 'Scan the same ID again right away', 'Refuse the scan: just scanned', $scan('STI-ATT-0001'), 422, 'Just scanned');

        $this->travel(5)->minutes();
        $r = $scan('STI-ATT-0001');
        $this->rec('ATC5', $m, 'Scan the same ID again after a few minutes (second scan)', 'Close the visit: check-out, with the length of stay', $this->said($r) . ' (action: ' . $r->json('action') . ', ' . $r->json('durationMinutes') . ' min)', $r->isSuccessful() && $r->json('action') === 'check_out');
        $this->travelBack();

        $other = $this->staff('librarian');
        $mate = $this->member(['barcodeValue' => 'STI-ATT-0003']);
        $this->signedOut();
        $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/scan', ['barcodeValue' => 'STI-ATT-0003'])->assertSuccessful();
        $told = SystemNotification::where('userID', $other->userID)->where('type', 'student_checked_in')->exists();
        $selfTold = SystemNotification::where('userID', $this->librarian->userID)->where('type', 'student_checked_in')->exists();
        $this->rec('ATC6', $m, 'A member checks in while another staff member is signed in', 'The other staff receive a check-in notification; the staff member who scanned does not', 'Other staff notified: ' . ($told ? 'yes' : 'no') . '; scanning staff notified: ' . ($selfTold ? 'yes' : 'no'), $told && ! $selfTold);

        $this->signedOut();
        $this->caseOf('ATC7', $m, 'Log a visitor without a name and purpose', 'Refuse the entry and ask for both', $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/visitor', ['name' => '', 'purpose' => '']), 422, 'required');

        $r = $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/visitor', ['name' => 'Maria Santos', 'purpose' => 'Research']);
        $this->rec('ATC8', $m, 'Log a walk-in visitor with a name and a purpose', 'Record the visit of the visitor', $this->said($r), $r->isSuccessful());
        $uuid = $r->json('log.uuid') ?? $r->json('uuid') ?? AttendanceLog::whereNotNull('visitorName')->value('uuid');

        $this->signedOut();
        $this->actingAs($this->librarian)->postJson("/api/librarian/attendance-logs/{$uuid}/visitor-checkout")->assertSuccessful();
        $this->signedOut();
        $this->caseOf('ATC9', $m, 'Check out the same visitor a second time', 'Refuse: the visitor is already checked out', $this->actingAs($this->librarian)->postJson("/api/librarian/attendance-logs/{$uuid}/visitor-checkout"), 422, 'already checked out');
    }

    // ---- 4. Catalog --------------------------------------------------------------------------------------------

    private function newBook(array $overrides = []): array
    {
        return array_merge([
            'title' => 'The Origin of Species', 'authors' => [['name' => 'Charles Darwin', 'role' => 'Author']], 'subjectName' => 'Science',
            'quantity' => 2, 'isbn' => '978-0-14-043205-3', 'publicationYear' => 1859, 'publisher' => 'John Murray', 'edition' => '1st ed.',
            'volume' => 'N/A', 'pageCount' => 502, 'sourceOfFund' => 'Library budget', 'cost' => 450,
        ], $overrides);
    }

    public function test_catalog(): void
    {
        $m = 'Catalog';
        $add = fn (array $o = []) => $this->actingAs($this->librarian)->postJson('/api/librarian/books', $this->newBook($o));

        $this->caseOf('BTC1', $m, 'Add a book without a title', 'Refuse the book and ask for the title', $add(['title' => '']), 422, 'title');
        $this->caseOf('BTC2', $m, 'Add a book without an author', 'Refuse the book and ask for an author', $add(['authors' => []]), 422, 'author');
        $this->caseOf('BTC3', $m, 'Add a book without a category', 'Refuse the book and ask for the category', $add(['subjectName' => '']), 422, 'subject');
        $this->caseOf('BTC4', $m, 'Enter a quantity of zero copies', 'Refuse the book: at least one copy', $add(['quantity' => 0]), 422, 'quantity');

        $r = $add();
        $book = Book::where('title', 'The Origin of Species')->first();
        $copies = $book ? $book->copies()->orderBy('copyID')->get() : collect();
        $this->rec('BTC5', $m, 'Add a complete book with 2 copies', 'Create the title, create 2 copies with their own accession numbers and barcodes',
            $this->said($r) . '; copies created: ' . $copies->count() . '; accession numbers ' . $copies->pluck('accessionNumber')->join(' and ') . '; barcodes ' . ($copies->every(fn ($c) => $c->barcodeValue) ? 'generated' : 'missing'),
            $r->getStatusCode() === 201 && $copies->count() === 2 && $copies->pluck('accessionNumber')->unique()->count() === 2);

        $this->signedOut();
        $this->caseOf('BTC6', $m, 'Add a copy to an existing title without the source of fund', 'Refuse the copy and ask for the source of fund', $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/copies", ['quantity' => 1]), 422, 'source of fund');
        $this->signedOut();
        $r = $this->actingAs($this->librarian)->postJson("/api/librarian/books/{$book->uuid}/copies", ['quantity' => 1, 'sourceOfFund' => 'Donation', 'cost' => 0]);
        $this->rec('BTC7', $m, 'Add one more copy to an existing title', 'A third copy is added with the next accession number', $this->said($r) . '; copies now: ' . $book->copies()->count(), $r->isSuccessful() && $book->copies()->count() === 3);

        $this->signedOut();
        $r = $this->actingAs($this->librarian)->putJson("/api/librarian/books/{$book->uuid}/dewey", ['deweyNumber' => '576.82']);
        $book->refresh();
        $this->rec('BTC8', $m, 'Set the Dewey number of a title by hand', 'The Dewey number is saved and the title is marked as checked', $this->said($r) . '; Dewey number ' . $book->deweyNumber . ', status ' . $book->deweyStatus, $r->isSuccessful() && $book->deweyNumber === '576.82' && $book->deweyStatus === 'ok');

        $this->signedOut();
        $student = $this->member();
        $r = $this->actingAs($student->user)->getJson('/api/student/catalog?search=Origin');
        $found = collect($r->json('data') ?? $r->json('books') ?? [])->pluck('title')->contains('The Origin of Species');
        $this->rec('BTC9', $m, 'A member searches the catalog for part of a title', 'The matching title is listed with its available copies', $this->said($r) . '; title found: ' . ($found ? 'yes' : 'no'), $r->isSuccessful() && $found);

        $this->signedOut();
        $r = $this->actingAs($student->user)->getJson('/api/student/catalog?search=zzzzqq');
        $this->rec('BTC10', $m, 'A member searches for a title that does not exist', 'An empty list, with no error', $this->said($r) . '; results: ' . count($r->json('data') ?? []), $r->isSuccessful() && count($r->json('data') ?? []) === 0);

        $this->signedOut();
        $r = $this->actingAs($this->librarian)->deleteJson("/api/librarian/books/{$book->uuid}");
        $kept = Book::withTrashed()->where('bookID', $book->bookID)->exists();
        $listed = Book::where('bookID', $book->bookID)->exists();
        $this->rec('BTC11', $m, 'Remove a title from the catalog', 'The title is soft-deleted (kept for history) and no longer listed', $this->said($r) . '; kept in database: ' . ($kept ? 'yes' : 'no') . '; listed: ' . ($listed ? 'yes' : 'no'), $r->isSuccessful() && $kept && ! $listed);
    }

    // ---- 5. Circulation ----------------------------------------------------------------------------------------

    public function test_circulation(): void
    {
        $m = 'Circulation';
        $this->travelTo(now()->startOfSecond());
        $student = $this->member();
        $book = $this->book();
        $copy = $book->copies()->first();
        $lend = fn (int $studentID, int $copyID) => $this->actingAs($this->librarian)->postJson('/api/librarian/loans', ['studentID' => $studentID, 'copyID' => $copyID]);

        $r = $lend($student->studentID, $copy->copyID);
        $loan = Loan::where('copyID', $copy->copyID)->first();
        $this->rec('CTC1', $m, 'Check out an available copy to an approved member', 'Create an Active loan, mark the copy borrowed, and set the due date from the loan period (3 days)',
            $this->said($r) . '; loan ' . ($loan?->status ?? 'missing') . '; copy ' . $copy->fresh()->status . '; due in ' . ($loan ? (int) round(now()->diffInHours($loan->dueDate, false) / 24) : '?') . ' days',
            $r->getStatusCode() === 201 && $loan?->status === 'Active' && $copy->fresh()->status === 'borrowed');

        $this->signedOut();
        $other = $this->member();
        $this->caseOf('CTC2', $m, 'Check out a copy that is already borrowed', 'Refuse: the copy cannot be checked out', $lend($other->studentID, $copy->copyID), 422, 'cannot be checked out');

        $this->signedOut();
        $thesis = $this->book('thesis');
        $this->caseOf('CTC3', $m, 'Check out a thesis (library use only)', 'Refuse: this collection cannot be borrowed', $lend($other->studentID, $thesis->copies()->first()->copyID), 422);

        $this->signedOut();
        $this->caseOf('CTC4', $m, 'Check out a copy that does not exist', 'Refuse the request: unknown copy', $lend($other->studentID, 999999), 422, 'invalid');

        $this->signedOut();
        $this->caseOf('CTC5', $m, 'A student assistant opens the loan list', 'Allowed: circulation is part of the assistant role', $this->actingAs($this->assistant)->getJson('/api/librarian/loans'), 200);

        $this->signedOut();
        $r = $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loan->uuid}/return");
        $this->rec('CTC6', $m, 'Receive a book back on time', 'The loan is closed, no fine is written, and the copy is set aside for the shelf check', $this->said($r) . '; loan ' . $loan->fresh()->status . '; fines: ' . $loan->penalties()->count() . '; copy ' . $copy->fresh()->status, $r->isSuccessful() && $loan->penalties()->count() === 0);
        $this->signedOut();
        $this->caseOf('CTC7', $m, 'Receive the same loan a second time', 'Refuse: the loan is no longer open', $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loan->uuid}/return"), 422);

        $type = PenaltyType::firstOrCreate(['kind' => 'overdue', 'areaOfLibrary' => 'circulation'], ['uuid' => Str::uuid()]);
        PenaltyRule::where('penaltyTypeID', $type->penaltyTypeID)->delete();
        PenaltyRule::create(['uuid' => Str::uuid(), 'penaltyTypeID' => $type->penaltyTypeID, 'rate' => 5, 'rateUnit' => 'day', 'gracePeriodDays' => 1]);
        $copy2 = $this->book()->copies()->first();
        $copy2->update(['status' => 'borrowed']);
        $late = Loan::create(['uuid' => Str::uuid(), 'studentID' => $student->studentID, 'copyID' => $copy2->copyID, 'loanType' => 'circulation', 'checkoutDate' => now()->subDays(12), 'dueDate' => now()->subDays(6), 'status' => 'Active']);
        $this->signedOut();
        $pv = $this->actingAs($this->librarian)->getJson("/api/librarian/loans/{$late->uuid}/return-preview");
        $this->rec('CTC8', $m, 'Preview the return of a book 6 days late (P5 a day, 1 grace day)', 'The preview shows the fine: 6 late days minus 1 grace day = 5 days x P5 = P25', $this->said($pv) . '; fine shown: ' . ($pv->json('preview.fine.amount') ?? '?') . ' for ' . ($pv->json('preview.fine.unitsLate') ?? '?') . ' day(s)', $pv->isSuccessful() && (float) $pv->json('preview.fine.amount') === 25.0);

        $this->signedOut();
        $r = $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$late->uuid}/return");
        $fine = $late->penalties()->first();
        $this->rec('CTC9', $m, 'Receive the late book', 'The loan is closed and an Unpaid fine of P25 is written for the member', $this->said($r) . '; fine ' . ($fine ? 'P' . number_format((float) $fine->amount, 2) . ' ' . $fine->paymentStatus : 'missing'), $r->isSuccessful() && $fine && (float) $fine->amount === 25.0 && $fine->paymentStatus === 'Unpaid');

        $this->signedOut();
        $b3 = $this->book();
        $ben = $this->member();
        $this->caseOf('CTC10', $m, 'Reserve with an empty cart', 'Refuse: add books to the cart first', $this->actingAs($ben->user)->postJson('/api/student/reservations'), 422, 'cart is empty');
        $this->signedOut();
        $this->actingAs($ben->user)->postJson('/api/student/cart', ['bookID' => $b3->bookID])->assertCreated();
        $this->signedOut();
        $r = $this->actingAs($ben->user)->postJson('/api/student/reservations');
        $res = Reservation::where('studentID', $ben->studentID)->first();
        $this->rec('CTC11', $m, 'Reserve the book in the cart', 'Create a Waiting reservation', $this->said($r) . '; reservation ' . ($res?->status ?? 'missing'), $r->isSuccessful() && $res?->status === 'Waiting');

        $this->signedOut();
        $this->actingAs($ben->user)->postJson('/api/student/cart', ['bookID' => $b3->bookID]);
        $this->signedOut();
        $this->caseOf('CTC12', $m, 'Reserve the same title again while the first reservation is active', 'Refuse: already in the active reservations', $this->actingAs($ben->user)->postJson('/api/student/reservations'), 422, 'already');

        $this->signedOut();
        $r = $this->actingAs($this->librarian)->postJson("/api/librarian/reservations/{$res->uuid}/accept");
        $this->rec('CTC13', $m, 'A librarian accepts the reservation', 'The reservation becomes Accepted and holds a copy', $this->said($r) . '; reservation ' . $res->fresh()->status, $r->isSuccessful() && $res->fresh()->status === 'Accepted');

        $this->signedOut();
        $copy4 = $this->book()->copies()->first();
        $copy4->update(['status' => 'borrowed']);
        $mine = Loan::create(['uuid' => Str::uuid(), 'studentID' => $ben->studentID, 'copyID' => $copy4->copyID, 'loanType' => 'circulation', 'checkoutDate' => now()->subDay(), 'dueDate' => now()->addDays(2), 'status' => 'Active']);
        $r = $this->actingAs($ben->user)->postJson("/api/student/loans/{$mine->uuid}/self-return");
        $this->rec('CTC14', $m, 'A member reports a book as returned while the desk is unattended', 'A Pending self-return report is created and the loan stays open until a librarian verifies it', $this->said($r) . '; loan ' . $mine->fresh()->status . '; report ' . ($mine->fresh()->selfReturnReport?->verificationStatus ?? 'missing'), $r->isSuccessful() && $mine->fresh()->selfReturnReport?->verificationStatus === 'Pending');

        $this->signedOut();
        $this->caseOf('CTC15', $m, 'The same member reports the same loan again', 'Refuse: a report is already pending', $this->actingAs($ben->user)->postJson("/api/student/loans/{$mine->uuid}/self-return"), 422, 'pending');

        $this->signedOut();
        $someone = $this->member();
        $this->caseOf('CTC16', $m, 'Another member tries to self-return somebody else\'s loan', 'Refuse: the loan does not belong to this member', $this->actingAs($someone->user)->postJson("/api/student/loans/{$mine->uuid}/self-return"), [403, 404, 422]);
    }

    // ---- 6. Penalties ------------------------------------------------------------------------------------------

    public function test_penalties(): void
    {
        $m = 'Penalties';
        $student = $this->member();
        $copy = $this->book()->copies()->first();
        $charge = fn (array $o = []) => $this->actingAs($this->librarian)->postJson('/api/librarian/penalties', array_merge(['type' => 'lost', 'amount' => 450, 'studentID' => $student->studentID, 'copyID' => $copy->copyID], $o));

        $this->caseOf('PTC1', $m, 'Charge a lost book with an amount of zero', 'Refuse: the amount must be greater than zero', $charge(['amount' => 0]), 422, 'greater than 0');
        $this->signedOut();
        $this->caseOf('PTC2', $m, 'Charge a lost book without a student or loan', 'Refuse: choose a loan, or a student and a copy', $charge(['studentID' => null, 'copyID' => null]), 422);

        $this->signedOut();
        $r = $charge();
        $pen = \App\Models\Penalty::where('studentID', $student->studentID)->first();
        $this->rec('PTC3', $m, 'Charge a lost book to a member (P450)', 'Create an Unpaid penalty for the member and the copy', $this->said($r) . '; penalty ' . ($pen ? 'P' . number_format((float) $pen->amount, 2) . ' ' . $pen->paymentStatus : 'missing'), $r->isSuccessful() && $pen && $pen->paymentStatus === 'Unpaid');

        $this->signedOut();
        $this->caseOf('PTC4', $m, 'Mark the penalty as paid without a receipt number', 'Refuse: a receipt number is needed', $this->actingAs($this->librarian)->postJson("/api/librarian/penalties/{$pen->uuid}/settle", []), 422, 'receipt');

        $this->signedOut();
        $r = $this->actingAs($this->librarian)->postJson("/api/librarian/penalties/{$pen->uuid}/settle", ['receiptNumber' => 'OR-2026-00123']);
        $this->rec('PTC5', $m, 'Mark the penalty as paid with the cashier\'s receipt number', 'The penalty becomes Paid with the receipt number, and the lost copy leaves the catalog', $this->said($r) . '; penalty ' . $pen->fresh()->paymentStatus . ', receipt ' . $pen->fresh()->receiptNumber . '; copy ' . $copy->fresh()->status, $r->isSuccessful() && $pen->fresh()->paymentStatus === 'Paid' && $copy->fresh()->status === 'retired');

        $this->signedOut();
        $this->caseOf('PTC6', $m, 'Mark the same penalty as paid a second time', 'Refuse: the penalty is already paid', $this->actingAs($this->librarian)->postJson("/api/librarian/penalties/{$pen->uuid}/settle", ['receiptNumber' => 'OR-2']), 422, 'already paid');

        $this->signedOut();
        $copy2 = $this->book()->copies()->first();
        $this->actingAs($this->librarian)->postJson('/api/librarian/penalties', ['type' => 'damaged', 'amount' => 120, 'studentID' => $student->studentID, 'copyID' => $copy2->copyID])->assertSuccessful();
        $pen2 = \App\Models\Penalty::where('copyID', $copy2->copyID)->first();
        $this->signedOut();
        $r = $this->actingAs($this->librarian)->postJson("/api/librarian/penalties/{$pen2->uuid}/cancel");
        $this->rec('PTC7', $m, 'Cancel a damaged-book charge', 'The charge is removed', $this->said($r) . '; charges left for that copy: ' . \App\Models\Penalty::where('copyID', $copy2->copyID)->count(), $r->isSuccessful());
    }

    // ---- 7. Engagement, suggestions and facilities --------------------------------------------------------------

    public function test_engagement_and_facilities(): void
    {
        $m = 'Engagement and facilities';
        $student = $this->member(['knowledgeScore' => 5]);
        $item = MarketItem::factory()->create(['pointCost' => 40, 'stock' => 3]);

        $r = $this->actingAs($student->user)->postJson("/api/student/market-items/{$item->uuid}/redeem");
        $this->rec('ETC1', $m, 'Redeem a 40-point reward with only 5 points', 'Refuse: not enough points; points and stock stay the same', $this->said($r) . '; points ' . $student->fresh()->knowledgeScore . ', stock ' . $item->fresh()->stock, $r->getStatusCode() === 422 && $student->fresh()->knowledgeScore === 5 && $item->fresh()->stock === 3);

        $this->signedOut();
        $student->update(['knowledgeScore' => 100]);
        $student = $student->fresh();
        $r = $this->actingAs($student->user)->postJson("/api/student/market-items/{$item->uuid}/redeem");
        $this->rec('ETC2', $m, 'Redeem the same reward with 100 points', 'Deduct 40 points, reduce the stock by 1, and create a Pending redemption', $this->said($r) . '; points ' . $student->fresh()->knowledgeScore . ', stock ' . $item->fresh()->stock . ', redemptions ' . \App\Models\PointRedemption::where('studentID', $student->studentID)->count(), $r->isSuccessful() && $student->fresh()->knowledgeScore === 60 && $item->fresh()->stock === 2);

        $this->signedOut();
        $this->caseOf('ETC3', $m, 'Suggest a book without a title', 'Refuse the suggestion and ask for the title', $this->actingAs($student->user)->postJson('/api/student/book-suggestions', ['title' => '']), 422, 'title');
        $this->signedOut();
        $r = $this->actingAs($student->user)->postJson('/api/student/book-suggestions', ['title' => 'Clean Code', 'author' => 'Robert C. Martin', 'reason' => 'On our syllabus']);
        $this->rec('ETC4', $m, 'Suggest a book with a title, author and reason', 'Create a Pending suggestion', $this->said($r), $r->isSuccessful());

        $this->signedOut();
        $computer = Resource::factory()->create(['status' => 'Available']);
        $use = fn (Student $s) => $this->actingAs($this->librarian)->postJson('/api/librarian/resource-usage-logs', ['resID' => $computer->resID, 'studentID' => $s->studentID]);
        $this->caseOf('ETC5', $m, 'Start a computer session for a member who has not checked in to the library', 'Refuse: the member must scan the ID at the station first', $use($student), 422, 'not logged in');
        $this->signedOut();
        $student->update(['barcodeValue' => 'STI-ENG-0001']);
        $this->actingAs($this->librarian)->postJson('/api/librarian/attendance-logs/scan', ['barcodeValue' => 'STI-ENG-0001'])->assertSuccessful();
        $this->signedOut();
        $r = $use($student);
        $this->rec('ETC6', $m, 'Start a session on an available computer for a member who is checked in', 'The session starts and the computer shows In Use', $this->said($r) . '; computer ' . $computer->fresh()->status, $r->isSuccessful() && $computer->fresh()->status === 'In Use');

        $this->signedOut();
        $second = $this->member();
        $this->caseOf('ETC7', $m, 'Start a second session on the same computer', 'Refuse: the computer is already in use', $use($second), 422);
    }

    // ---- 8. Access control and administration ------------------------------------------------------------------

    public function test_access_and_administration(): void
    {
        $m = 'Access and administration';

        $this->caseOf('ACT1', $m, 'A student assistant opens the Reports page data', 'Refuse: reports are not part of the assistant role', $this->actingAs($this->assistant)->getJson('/api/librarian/reports/overview'), 403);
        $this->signedOut();
        $this->caseOf('ACT2', $m, 'A student assistant opens the Engagement page data', 'Refuse: not part of the assistant role', $this->actingAs($this->assistant)->getJson('/api/librarian/achievements'), 403);
        $this->signedOut();
        $this->caseOf('ACT3', $m, 'A librarian opens the Reports page data', 'Allowed', $this->actingAs($this->librarian)->getJson('/api/librarian/reports/overview'), 200);
        $this->signedOut();
        $this->caseOf('ACT4', $m, 'A librarian opens an administrator page', 'Refuse: administrators only', $this->actingAs($this->librarian)->getJson('/api/admin/staff'), 403);
        $this->signedOut();
        $this->caseOf('ACT5', $m, 'A member opens an administrator page', 'Refuse', $this->actingAs($this->member()->user)->getJson('/api/admin/staff'), [401, 403]);

        $this->signedOut();
        $r = $this->actingAs($this->admin)->postJson('/api/admin/staff', ['firstName' => 'Paolo', 'lastName' => 'Lim', 'email' => 'paolo@stidavao.edu.ph', 'role' => 'assistant']);
        $this->rec('ACT6', $m, 'An administrator creates a student-assistant account', 'The account is created and must change its temporary password at first sign-in', $this->said($r) . '; must change password: ' . (User::where('email', 'paolo@stidavao.edu.ph')->value('mustChangePassword') ? 'yes' : 'no'), $r->isSuccessful() && (bool) User::where('email', 'paolo@stidavao.edu.ph')->value('mustChangePassword'));

        $this->signedOut();
        $this->caseOf('ACT7', $m, 'An administrator creates another account with the same email', 'Refuse: email already taken', $this->actingAs($this->admin)->postJson('/api/admin/staff', ['firstName' => 'Paolo', 'lastName' => 'Lim', 'email' => 'paolo@stidavao.edu.ph', 'role' => 'assistant']), 422, 'taken');

        $this->signedOut();
        $paolo = User::where('email', 'paolo@stidavao.edu.ph')->first()->librarian;
        $this->actingAs($this->admin)->postJson("/api/admin/staff/{$paolo->uuid}/disable")->assertSuccessful();
        $this->signedOut();
        User::where('email', 'paolo@stidavao.edu.ph')->update(['password' => bcrypt('password123')]);
        $this->caseOf('ACT8', $m, 'A disabled staff account tries to sign in with its correct password', 'Refuse: the account has been disabled', $this->postJson('/api/librarian/login', ['email' => 'paolo@stidavao.edu.ph', 'password' => 'password123']), 422, 'disabled');

        $this->signedOut();
        $r = $this->actingAs($this->admin)->getJson('/api/admin/audit-logs');
        $this->rec('ACT9', $m, 'An administrator opens the audit log after the actions above', 'The log lists the sensitive actions with who did them', $this->said($r) . '; entries: ' . count($r->json('logs.data') ?? []), $r->isSuccessful() && count($r->json('logs.data') ?? []) > 0);
    }
}
