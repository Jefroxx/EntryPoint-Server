<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The student Home's "New arrivals" card: only the books marked new, the most recently added first. */
class NewArrivalsCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_librarian_can_mark_and_unmark_an_existing_book_as_new(): void
    {
        BookSubject::factory()->create();
        $librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
        $book = Book::factory()->create(['isNewArrival' => false, 'newArrivalAt' => null]);
        $url = "/api/librarian/books/{$book->uuid}";

        $this->actingAs($librarian)->patchJson($url, ['isNewArrival' => true])->assertOk();
        $this->assertTrue($book->fresh()->isNewArrival);
        $marked = $book->fresh()->newArrivalAt;
        $this->assertNotNull($marked);

        // Saving again with the mark still on does not restart its clock.
        $this->travel(2)->days();
        $this->actingAs($librarian)->patchJson($url, ['isNewArrival' => true, 'title' => 'Renamed'])->assertOk();
        $this->assertTrue($book->fresh()->newArrivalAt->equalTo($marked));

        $this->actingAs($librarian)->patchJson($url, ['isNewArrival' => false])->assertOk();
        $this->assertFalse($book->fresh()->isNewArrival);
        $this->assertNull($book->fresh()->newArrivalAt);
    }

    public function test_only_new_arrivals_are_listed_newest_first(): void
    {
        BookSubject::factory()->create();
        $student = Student::factory()->create(['registrationStatus' => 'approved']);

        $old = Book::factory()->create(['title' => 'Older Arrival', 'isNewArrival' => true, 'newArrivalAt' => now()->subDays(10)]);
        $new = Book::factory()->create(['title' => 'Newest Arrival', 'isNewArrival' => true, 'newArrivalAt' => now()->subDay()]);
        $plain = Book::factory()->create(['title' => 'Not New', 'isNewArrival' => false, 'newArrivalAt' => null]);
        foreach ([$old, $new, $plain] as $book) {
            BookCopy::factory()->create(['bookID' => $book->bookID]);
        }

        $titles = $this->actingAs($student->user)->getJson('/api/student/catalog?newArrivalsOnly=1')
            ->assertOk()
            ->json('data.*.title');

        $this->assertSame(['Newest Arrival', 'Older Arrival'], $titles);

        // Without the filter, the whole catalog (alphabetical) comes back as before.
        $this->actingAs($student->user)->getJson('/api/student/catalog')
            ->assertOk()->assertJsonCount(3, 'data');
    }
}
