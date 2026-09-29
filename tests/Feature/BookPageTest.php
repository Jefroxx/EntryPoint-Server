<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\BookSubject;
use App\Models\Librarian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookPageTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        BookSubject::factory()->create();
        $this->book = Book::factory()->create();

        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    /** A real 1×1 PNG: the upload rule checks the file's contents, not just its name. */
    private function photo(string $name = 'page.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    public function test_photos_are_stored_in_order_and_shown_in_the_book_details(): void
    {
        $this->actingAs($this->librarian)
            ->post("/api/librarian/books/{$this->book->bookID}/pages", [
                'section' => 'table_of_contents',
                'photos'  => [$this->photo('toc-1.png'), $this->photo('toc-2.png')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'pages')
            ->assertJsonPath('pages.1.position', 2);

        $this->post("/api/librarian/books/{$this->book->bookID}/pages", ['section' => 'index', 'photos' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertCreated();

        foreach (BookPage::all() as $page) {
            Storage::disk('public')->assertExists($page->path);
        }

        $pages = $this->getJson("/api/librarian/books/{$this->book->bookID}")->assertOk()->json('book.pages');
        // Book order: the table of contents comes before the index.
        $this->assertSame(['table_of_contents', 'table_of_contents', 'index'], array_column($pages, 'section'));
        $this->assertStringContainsString('/storage/book-pages/', $pages[0]['url']);
    }

    public function test_students_see_the_photos_under_look_inside(): void
    {
        $this->actingAs($this->librarian)
            ->post("/api/librarian/books/{$this->book->bookID}/pages", ['section' => 'about_the_author', 'photos' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertCreated();

        $student = Student::factory()->create(['registrationStatus' => 'approved']);

        $this->actingAs($student->user)
            ->getJson("/api/student/catalog/{$this->book->bookID}")
            ->assertOk()
            ->assertJsonPath('book.pages.0.section', 'about_the_author');
    }

    public function test_a_section_can_be_reordered_and_a_photo_removed(): void
    {
        $this->actingAs($this->librarian)
            ->post("/api/librarian/books/{$this->book->bookID}/pages", ['section' => 'bibliography', 'photos' => [$this->photo(), $this->photo(), $this->photo()]], ['Accept' => 'application/json'])
            ->assertCreated();

        [$a, $b, $c] = BookPage::orderBy('position')->pluck('pageID')->all();

        $this->putJson("/api/librarian/books/{$this->book->bookID}/pages/order", ['section' => 'bibliography', 'pageIDs' => [$c, $a, $b]])->assertOk();
        $this->assertSame([$c, $a, $b], BookPage::orderBy('position')->pluck('pageID')->all());

        $path = BookPage::find($a)->path;
        $this->deleteJson("/api/librarian/book-pages/{$a}")->assertOk();
        $this->assertNull(BookPage::find($a));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_only_images_are_accepted_and_students_cannot_upload(): void
    {
        $this->actingAs($this->librarian)
            ->post("/api/librarian/books/{$this->book->bookID}/pages", [
                'section' => 'index',
                'photos'  => [UploadedFile::fake()->createWithContent('notes.pdf', '%PDF-1.4 not an image')],
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photos.0');

        $student = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->actingAs($student->user)
            ->post("/api/librarian/books/{$this->book->bookID}/pages", ['section' => 'index', 'photos' => [$this->photo()]], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
