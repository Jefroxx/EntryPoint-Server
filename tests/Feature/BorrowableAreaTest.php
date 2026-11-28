<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\LoanPeriod;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A book in a library-use-only area (the area's lending rule says it can't be borrowed) can't be carted or reserved. */
class BorrowableAreaTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;
    private Book $lendable;
    private Book $libraryOnly;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create(['name' => 'Filipino Literature']);
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        LoanPeriod::updateOrCreate(['area' => 'reserved'], ['loanable' => false, 'periodValue' => null, 'periodUnit' => null]);

        $this->student = Student::factory()->create(['registrationStatus' => 'approved']);
        $this->lendable = Book::factory()->create(['areaOfLibrary' => 'circulation']);
        $this->libraryOnly = Book::factory()->create(['areaOfLibrary' => 'reserved']);
        BookCopy::factory()->create(['bookID' => $this->lendable->bookID]);
        BookCopy::factory()->create(['bookID' => $this->libraryOnly->bookID]);
    }

    public function test_a_library_use_only_book_cannot_be_added_to_the_cart(): void
    {
        $this->actingAs($this->student->user)->postJson('/api/student/cart', ['bookID' => $this->libraryOnly->bookID])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bookID');

        $this->assertDatabaseMissing('wishlists', ['studentID' => $this->student->studentID, 'bookID' => $this->libraryOnly->bookID]);
    }

    public function test_a_borrowable_book_can_still_be_added_and_reserved(): void
    {
        $this->actingAs($this->student->user)->postJson('/api/student/cart', ['bookID' => $this->lendable->bookID])->assertCreated();
        $this->actingAs($this->student->user)->postJson('/api/student/reservations')->assertCreated();
    }

    public function test_a_book_carted_before_its_area_became_library_use_only_cannot_be_reserved(): void
    {
        $this->actingAs($this->student->user)->postJson('/api/student/cart', ['bookID' => $this->lendable->bookID])->assertCreated();

        LoanPeriod::where('area', 'circulation')->update(['loanable' => false]);

        $this->actingAs($this->student->user)->postJson('/api/student/reservations')
            ->assertStatus(422)
            ->assertJsonValidationErrors('cart');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_the_catalog_tells_the_app_whether_a_book_is_borrowable(): void
    {
        $this->actingAs($this->student->user)->getJson("/api/student/catalog/{$this->libraryOnly->uuid}")
            ->assertJsonPath('book.borrowable', false);
        $this->actingAs($this->student->user)->getJson("/api/student/catalog/{$this->lendable->uuid}")
            ->assertJsonPath('book.borrowable', true);
    }
}
