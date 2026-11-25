<?php

namespace Tests\Feature;

use App\Models\BookSuggestion;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** After a book request is approved, the librarian marks the book as arrived, and the student who asked is told. */
class BookRequestArrivedTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function request(string $status): BookSuggestion
    {
        return BookSuggestion::factory()->create([
            'studentID'    => Student::factory()->create()->studentID,
            'title'        => 'Clean Code',
            'status'       => $status,
            'progressStep' => $status === 'Pending' ? 'Submitted' : $status,
        ]);
    }

    public function test_an_approved_request_can_be_marked_arrived_and_the_student_is_told(): void
    {
        $suggestion = $this->request('Approved');

        $this->actingAs($this->librarian)->postJson("/api/librarian/book-suggestions/{$suggestion->uuid}/arrived")
            ->assertOk()->assertJsonPath('suggestion.status', 'Arrived')->assertJsonPath('suggestion.progressStep', 'Arrived');

        $note = SystemNotification::where('userID', $suggestion->studentID)->where('type', 'book_suggestion_arrived')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Clean Code', $note->message);
        $this->assertStringContainsString('arrived in the library', $note->message);
    }

    public function test_only_an_approved_request_can_be_marked_arrived(): void
    {
        foreach (['Pending', 'Rejected', 'Arrived'] as $status) {
            $suggestion = $this->request($status);
            $this->actingAs($this->librarian)->postJson("/api/librarian/book-suggestions/{$suggestion->uuid}/arrived")->assertUnprocessable();
            $this->assertSame($status, $suggestion->fresh()->status);
        }
    }
}
