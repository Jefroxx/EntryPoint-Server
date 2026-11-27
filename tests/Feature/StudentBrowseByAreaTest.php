<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookSubject;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Students browse the catalog by area of the library (Circulation, Filipiniana, ...), not by genre. */
class StudentBrowseByAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->create();
        Book::factory()->count(2)->create(['areaOfLibrary' => 'circulation']);
        Book::factory()->create(['areaOfLibrary' => 'filipiniana']);

        $this->actingAs(Student::factory()->approved()->create()->user);
    }

    public function test_the_areas_list_has_only_areas_with_books_in_the_library_order(): void
    {
        $this->getJson('/api/student/catalog/areas')->assertOk()->assertExactJson(['areas' => [
            ['key' => 'circulation', 'name' => 'Circulation', 'books' => 2],
            ['key' => 'filipiniana', 'name' => 'Filipiniana', 'books' => 1],
        ]]);
    }

    public function test_the_catalog_filters_by_area_and_says_each_books_area(): void
    {
        $this->getJson('/api/student/catalog?area=filipiniana')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.area', ['key' => 'filipiniana', 'name' => 'Filipiniana']);
    }

    public function test_an_unknown_area_is_refused(): void
    {
        $this->getJson('/api/student/catalog?area=nowhere')->assertUnprocessable();
    }
}
