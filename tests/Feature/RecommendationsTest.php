<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\Loan;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The student Home's "Recommended" card: a mix of the Dewey classes the student borrows from most (their own subjects
 * first inside a class) and what everyone borrows.
 */
class RecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private function bookIn(BookSubject $subject, string $title, string $dewey): Book
    {
        $book = Book::factory()->create(['title' => $title, 'subjectID' => $subject->subjectID, 'deweyNumber' => $dewey]);
        BookCopy::factory()->create(['bookID' => $book->bookID, 'status' => 'available']);

        return $book;
    }

    private function borrow(Student $student, Book $book, string $status = 'Returned'): void
    {
        $copy = BookCopy::where('bookID', $book->bookID)->first() ?? BookCopy::factory()->create(['bookID' => $book->bookID]);
        Loan::create([
            'uuid' => Str::uuid(), 'studentID' => $student->studentID, 'copyID' => $copy->copyID, 'loanType' => 'circulation',
            'checkoutDate' => now()->subDays(20), 'dueDate' => now()->subDays(13),
            'returnDate' => $status === 'Returned' ? now()->subDays(14) : null, 'status' => $status,
        ]);
    }

    public function test_the_class_borrowed_most_comes_first_with_their_own_subject_first_then_popular_books(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $biology = BookSubject::factory()->create(['name' => 'Biology']);
        $chemistry = BookSubject::factory()->create(['name' => 'Chemistry']);
        $history = BookSubject::factory()->create(['name' => 'History']);
        $law = BookSubject::factory()->create(['name' => 'Law']);

        // Three returned loans in the 500s (Biology), one in the 900s: Science is the favourite class.
        $this->borrow($student, $this->bookIn($biology, 'Read A', '570.1'));
        $this->borrow($student, $this->bookIn($biology, 'Read B', '571.2'));
        $this->borrow($student, $this->bookIn($biology, 'Read C', '572.3'));
        $this->borrow($student, $this->bookIn($history, 'Read D', '909.1'));

        $this->bookIn($chemistry, 'Chem Pick', '540.1');      // same class, another subject: the class widens the list
        $this->bookIn($biology, 'Bio Next', '574.1');         // same class and subject: first
        $this->bookIn($history, 'History Pick', '909.2');
        $popular = $this->bookIn($law, 'Law Popular', '340.1'); // nothing to do with them, but everyone borrows it
        $this->borrow($other, $popular);
        $this->borrow($other, $popular);

        $json = $this->actingAs($student->user)->getJson('/api/student/recommendations')->assertOk();
        $titles = $json->json('books.*.title');

        $this->assertSame(['Bio Next', 'Chem Pick', 'History Pick', 'Law Popular'], $titles);
        $this->assertSame(['Science', 'History and Geography'], $json->json('classes'));
        $this->assertSame(['Biology', 'History'], $json->json('subjects'));
        $this->assertSame(4, $json->json('loanCount'));
        // Books already borrowed are never suggested.
        $this->assertNotContains('Read A', $titles);
    }

    public function test_popular_books_keep_three_places_even_when_the_class_has_plenty(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $science = BookSubject::factory()->create(['name' => 'Physics']);
        $arts = BookSubject::factory()->create(['name' => 'Music']);

        $this->borrow($student, $this->bookIn($science, 'Read', '530.0'));
        foreach (range(1, 12) as $n) {
            $this->bookIn($science, sprintf('Physics %02d', $n), sprintf('530.%02d', $n));
        }
        foreach (range(1, 4) as $n) {
            $hit = $this->bookIn($arts, "Music $n", "780.$n");
            foreach (range(1, $n) as $_) {
                $this->borrow($other, $hit);
            }
        }

        $titles = $this->actingAs($student->user)->getJson('/api/student/recommendations')->assertOk()->json('books.*.title');

        $this->assertCount(10, $titles);
        $this->assertSame(array_map(fn ($n) => sprintf('Physics %02d', $n), range(1, 7)), array_slice($titles, 0, 7));
        // The three most borrowed of the rest, most borrowed first.
        $this->assertSame(['Music 4', 'Music 3', 'Music 2'], array_slice($titles, 7));
    }

    public function test_a_book_with_no_dewey_number_yet_takes_its_class_from_its_subject(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $chemistry = BookSubject::factory()->create(['name' => 'Chemistry', 'classificationCode' => '540']);
        $physics = BookSubject::factory()->create(['name' => 'Physics', 'classificationCode' => '530']);
        $law = BookSubject::factory()->create(['name' => 'Law', 'classificationCode' => '340']);

        $read = Book::factory()->create(['title' => 'Read', 'subjectID' => $chemistry->subjectID, 'deweyNumber' => null]);
        BookCopy::factory()->create(['bookID' => $read->bookID, 'status' => 'available']);
        $this->borrow($student, $read);
        $near = Book::factory()->create(['title' => 'Physics Near', 'subjectID' => $physics->subjectID, 'deweyNumber' => null]);
        BookCopy::factory()->create(['bookID' => $near->bookID, 'status' => 'available']);
        $far = Book::factory()->create(['title' => 'Law Far', 'subjectID' => $law->subjectID, 'deweyNumber' => null]);
        BookCopy::factory()->create(['bookID' => $far->bookID, 'status' => 'available']);

        $json = $this->actingAs($student->user)->getJson('/api/student/recommendations')->assertOk();

        // Physics shares Chemistry's 500s, so it is the student's pick; Law is only there to fill.
        $this->assertSame(['Physics Near', 'Law Far'], $json->json('books.*.title'));
        $this->assertSame(['Science'], $json->json('classes'));
    }

    public function test_a_new_student_gets_the_most_borrowed_books_and_no_reason_line(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $subject = BookSubject::factory()->create();
        $this->bookIn($subject, 'Alpha', '100.1');
        $hit = $this->bookIn($subject, 'Omega', '100.2');
        $this->borrow($other, $hit);

        $json = $this->actingAs($student->user)->getJson('/api/student/recommendations')->assertOk();

        $this->assertSame(['Omega', 'Alpha'], $json->json('books.*.title'));
        $this->assertSame([], $json->json('subjects'));
        $this->assertSame([], $json->json('classes'));
    }

    public function test_it_never_returns_more_than_ten_books(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $subject = BookSubject::factory()->create();
        foreach (range(1, 13) as $n) {
            $this->bookIn($subject, sprintf('Book %02d', $n), sprintf('600.%02d', $n));
        }

        $this->actingAs($student->user)->getJson('/api/student/recommendations')
            ->assertOk()->assertJsonCount(10, 'books');
    }
}
