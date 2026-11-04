<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** A book only counts as a new arrival when the librarian ticks it; then every approved member is told. */
class NewArrivalTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Science']);
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function add(array $overrides = [])
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/books', array_merge([
            'title'           => 'Cosmos',
            'authors'         => [['name' => 'Carl Sagan', 'role' => 'Author']],
            'subjectName'     => 'Science',
            'quantity'        => 1,
            'isbn'            => '978-0-375-50832-5',
            'publicationYear' => 1980,
            'publisher'       => 'Random House',
            'edition'         => '1st ed.',
            'volume'          => 'N/A',
            'pageCount'       => 365,
            'sourceOfFund'    => 'Donation',
            'cost'            => 0,
        ], $overrides));
    }

    public function test_an_old_book_is_not_a_new_arrival_and_nobody_is_notified(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);

        $this->add()->assertCreated();

        $book = Book::where('title', 'Cosmos')->firstOrFail();
        $this->assertFalse($book->isNewArrival);
        $this->assertNull($book->newArrivalAt);
        $this->assertSame(0, SystemNotification::where('userID', $student->studentID)->where('type', 'new_arrival')->count());
    }

    public function test_a_ticked_book_is_flagged_and_approved_members_are_notified(): void
    {
        $approved = Student::factory()->create(['registrationStatus' => 'approved']);
        $pending = Student::factory()->create(['registrationStatus' => 'pending']);

        $this->add(['isNewArrival' => true])->assertCreated();

        $book = Book::where('title', 'Cosmos')->firstOrFail();
        $this->assertTrue($book->isNewArrival);
        $this->assertNotNull($book->newArrivalAt);
        $this->assertSame(1, SystemNotification::where('userID', $approved->studentID)->where('type', 'new_arrival')->count());
        $this->assertSame(0, SystemNotification::where('userID', $pending->studentID)->count());
    }
}
