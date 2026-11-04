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

/** The student Home's "Recommended" card: the subjects they borrow most, then what everyone borrows. */
class RecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private function bookIn(BookSubject $subject, string $title): Book
    {
        $book = Book::factory()->create(['title' => $title, 'subjectID' => $subject->subjectID]);
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

    public function test_the_subject_borrowed_most_comes_first_counting_returned_loans_and_popularity_breaks_ties(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $cs = BookSubject::factory()->create(['name' => 'Computer Science']);
        $art = BookSubject::factory()->create(['name' => 'Fine Arts']);

        // Three returned loans in Computer Science, one in Fine Arts: CS is the favourite.
        foreach (['Read A', 'Read B', 'Read C'] as $title) {
            $this->borrow($student, $this->bookIn($cs, $title));
        }
        $this->borrow($student, $this->bookIn($art, 'Read D'));

        $quiet = $this->bookIn($cs, 'Aaa Quiet CS');
        $popular = $this->bookIn($cs, 'Zzz Popular CS');
        $this->bookIn($art, 'Art Pick');
        $unrelated = Book::factory()->create(['title' => 'Unrelated', 'subjectID' => BookSubject::factory()->create(['name' => 'Law'])->subjectID]);
        BookCopy::factory()->create(['bookID' => $unrelated->bookID, 'status' => 'available']);
        // Others borrow the second CS book, so it outranks the alphabetically-first one.
        $this->borrow($other, $popular);
        $this->borrow($other, $popular);

        $json = $this->actingAs($student->user)->getJson('/api/student/recommendations')->assertOk();
        $titles = $json->json('books.*.title');

        // CS (3 loans) before Fine Arts (1) before anything else; the popular CS book ahead of the quiet one.
        $this->assertSame(['Zzz Popular CS', 'Aaa Quiet CS', 'Art Pick', 'Unrelated'], $titles);
        $this->assertSame(['Computer Science', 'Fine Arts'], $json->json('subjects'));
        $this->assertSame(4, $json->json('loanCount'));
        // Books already borrowed are never suggested.
        $this->assertNotContains('Read A', $titles);
    }

    public function test_a_new_student_gets_the_most_borrowed_books_and_no_subject_line(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $other = Student::factory()->create(['registrationStatus' => 'approved']);
        $subject = BookSubject::factory()->create();
        $this->bookIn($subject, 'Alpha');
        $hit = $this->bookIn($subject, 'Omega');
        $this->borrow($other, $hit);

        $json = $this->actingAs($student->user)->getJson('/api/student/recommendations')->assertOk();

        $this->assertSame(['Omega', 'Alpha'], $json->json('books.*.title'));
        $this->assertSame([], $json->json('subjects'));
    }

    public function test_it_never_returns_more_than_ten_books(): void
    {
        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $subject = BookSubject::factory()->create();
        foreach (range(1, 13) as $n) {
            $this->bookIn($subject, sprintf('Book %02d', $n));
        }

        $this->actingAs($student->user)->getJson('/api/student/recommendations')
            ->assertOk()->assertJsonCount(10, 'books');
    }
}
