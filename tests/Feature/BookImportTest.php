<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Librarian;
use App\Models\LoanPeriod;
use App\Models\User;
use App\Services\BookImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Bringing the library's accession record (Excel) into the catalog. The rows are shaped like the real 2026 record. */
class BookImportTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['circulation' => 'Circulation', 'filipiniana' => 'Filipiniana', 'reserved' => 'Reserve', 'fiction' => 'Fiction', 'general-reference' => 'General Reference'] as $area => $name) {
            LoanPeriod::updateOrCreate(['area' => $area], ['name' => $name, 'loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        }
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);
    }

    private function import(array $rows, bool $dryRun = false)
    {
        return $this->actingAs($this->librarian)->postJson('/api/librarian/books/import', ['rows' => $rows, 'dryRun' => $dryRun, 'fileName' => '2026 Accession Record.xlsx']);
    }

    public function test_copies_of_one_title_become_one_book_keeping_the_record_s_accession_numbers(): void
    {
        $row = ['callNumber' => 'Fil 515.3 AS843 2023', 'authors' => ' Aspuria Corazon S. ', 'title' => 'Differential Calculus A Work Text',
            'pages' => '240', 'sourceOfFund' => 'Library funds ', 'cost' => '795', 'year' => '2023', 'remarks' => 'ISBN: 978-621-427-126-9', 'dateReceived' => '2026-03-19'];

        $this->import([['row' => 2, 'accessionNumber' => '3540'] + $row, ['row' => 3, 'accessionNumber' => '3541'] + $row])
            ->assertOk()->assertJson(['books' => 1, 'copies' => 2, 'skipped' => []]);

        $book = Book::where('title', 'Differential Calculus A Work Text')->firstOrFail();
        $this->assertSame('filipiniana', $book->areaOfLibrary);
        $this->assertSame('515.3', $book->deweyNumber);
        $this->assertSame('AS843', $book->cutter);
        $this->assertSame('ok', $book->deweyStatus);
        $this->assertSame('9786214271269', $book->isbn);
        $this->assertSame(240, $book->pageCount);
        $this->assertSame(['Aspuria Corazon S.'], $book->authors->pluck('name')->all());

        $copies = BookCopy::where('bookID', $book->bookID)->orderBy('accessionNumber')->get();
        $this->assertSame([3540, 3541], $copies->pluck('accessionNumber')->all());
        $this->assertSame('2026-03-19', $copies[0]->dateReceived->toDateString());
        $this->assertSame('Library Fund', $copies[0]->sourceOfFund);
        $this->assertEquals(795, $copies[0]->cost);
        $this->assertDatabaseHas('book_stock_logs', ['copyID' => $copies[0]->copyID, 'action' => 'added']);

        // A new copy added by hand takes the next number after the record's.
        $next = app(\App\Services\CatalogService::class)->addCopies($book, 1, ['sourceOfFund' => 'Donated', 'cost' => 0]);
        $this->assertSame(3542, $next->first()->accessionNumber);
    }

    public function test_a_dry_run_reports_without_saving_and_skips_what_cannot_be_brought_in_safely(): void
    {
        \App\Models\BookSubject::factory()->create();
        $taken = Book::factory()->create();
        BookCopy::factory()->create(['bookID' => $taken->bookID, 'accessionNumber' => 4000]);

        $rows = [
            ['row' => 2, 'accessionNumber' => '3387', 'title' => 'Realworld Software Configuration', 'authors' => 'Kenefick, Sean'],
            ['row' => 3, 'accessionNumber' => '3387', 'title' => 'Textbook on the Philippine Constitution', 'authors' => 'De Leon, Hector S.'],
            ['row' => 4, 'accessionNumber' => '4000', 'title' => 'Biology', 'authors' => 'Mader, Sylvia S.'],
            ['row' => 5, 'accessionNumber' => '6312'], // a number the record lists but never used
            ['row' => 6, 'accessionNumber' => '4802', 'callNumber' => 'Fil.398.2095C165', 'pages' => '106'], // details, no title
            ['row' => 7, 'accessionNumber' => '4100', 'title' => 'Environmental Science', 'authors' => 'Miller, Tyler G. Jr'],
        ];

        $result = $this->import($rows, dryRun: true)->assertOk()->json();

        $this->assertSame(1, $result['books']);
        $this->assertSame(1, $result['copies']);
        $this->assertSame(1, $result['emptyRows']);
        $reasons = collect($result['skipped'])->keyBy('row')->map(fn ($s) => $s['reason']);
        $this->assertStringContainsString('used by 2 rows', $reasons[2]);
        $this->assertStringContainsString('used by 2 rows', $reasons[3]);
        $this->assertStringContainsString('already in the catalog', $reasons[4]);
        $this->assertStringContainsString('No title', $reasons[6]);
        // Nothing was saved.
        $this->assertDatabaseMissing('books', ['title' => 'Environmental Science']);
    }

    public function test_the_class_column_gives_the_area_the_dewey_number_and_the_cutter(): void
    {
        $service = app(BookImportService::class);

        $this->assertSame(['circulation', '610.73', 'P34', false], $service->parseClass('Cir.610.73P34'));
        $this->assertSame(['reserved', '004.165', 'B75', false], $service->parseClass('Ref.004.165B75'));
        $this->assertSame(['general-reference', '133.43', 'G13', false], $service->parseClass('Gen.Ref133.43G13'));
        $this->assertSame(['circulation', '617.075', 'Su8', false], $service->parseClass('CirINC617.075Su8'));
        $this->assertSame(['circulation', '610.73', 'L96', false], $service->parseClass('Cir,610.73L96'));
        // A year typed straight after the Cutter is dropped.
        $this->assertSame(['circulation', '616.1', 'C11', false], $service->parseClass('Cir616.1C112007'));
        $this->assertSame(['filipiniana', '170', 'M115', false], $service->parseClass('Fil.170M1152010'));
        // Fiction is shelved by the author mark alone.
        $this->assertSame(['fiction', null, 'D69s', false], $service->parseClass('Fic. D69s 1997'));
        // No area, and a class that can't be read, are said so rather than guessed.
        $this->assertSame([null, '005.74', 'H67m', false], $service->parseClass('005.74H67m2005'));
        $this->assertSame(['filipiniana', null, null, true], $service->parseClass('Fil.36.3D'));
    }

    public function test_dates_are_read_in_the_record_s_forms_and_impossible_ones_are_not_guessed(): void
    {
        $service = app(BookImportService::class);

        $this->assertSame(['2005-03-12', false], $service->parseDate('03-12,05'));
        $this->assertSame(['2005-03-12', false], $service->parseDate('03-12,-05'));
        $this->assertSame(['2026-03-19', false], $service->parseDate('03/19/2026'));
        $this->assertSame(['2026-03-19', false], $service->parseDate('03/19/26'));
        $this->assertSame(['2026-03-19', false], $service->parseDate('2026-03-19'));
        $this->assertSame([null, true], $service->parseDate('03-11,116'));
        $this->assertSame([null, false], $service->parseDate(''));
    }

    public function test_an_incomplete_record_comes_in_and_says_what_it_lacks_and_unread_text_is_kept(): void
    {
        $this->import([
            ['row' => 2, 'accessionNumber' => '4027', 'title' => 'NSTP National Development via National Service', 'authors' => 'Gasilla - Delacrus , Sonia',
                'callNumber' => 'Fil.36.3D', 'pages' => '200', 'publisher' => 'Booka Atbp. Publishing Corp.', 'year' => '2005', 'dateReceived' => '03-12,07', 'dateAutofilled' => true],
            ['row' => 3, 'accessionNumber' => '4028', 'title' => 'Ethics in Nursing Practice', 'callNumber' => '', 'volume' => 'c-1', 'remarks' => 'Discarded'],
        ])->assertOk()->assertJsonPath('notes.unreadCallNumber', 1)->assertJsonPath('notes.unreadDate', 1)->assertJsonPath('notes.noArea', 1);

        $nstp = Book::where('title', 'NSTP National Development via National Service')->firstOrFail();
        $this->assertNull($nstp->deweyNumber);
        $this->assertSame('needs_review', $nstp->deweyStatus);
        $this->assertSame('Call number in the accession record: Fil.36.3D', $nstp->remarks);
        $this->assertSame(['Gasilla - Delacrus, Sonia'], $nstp->authors->pluck('name')->all());
        $this->assertContains('ISBN', $nstp->missingDetails());
        // A date Excel counted up when a cell was dragged down isn't believed; the text stays on the copy.
        $copy = BookCopy::where('accessionNumber', 4027)->firstOrFail();
        $this->assertNull($copy->dateReceived);
        $this->assertStringContainsString('Date received in the record: 03-12,07', $copy->remarks);

        $ethics = Book::where('title', 'Ethics in Nursing Practice')->firstOrFail();
        $this->assertSame('circulation', $ethics->areaOfLibrary);
        $this->assertNull($ethics->volume); // "c-1" is the copy number
        $this->assertSame('retired', BookCopy::where('accessionNumber', 4028)->value('status'));

        // The Library page tags it.
        $row = collect($this->getJson('/api/books?search=NSTP')->json('data'))->first();
        $this->assertContains('ISBN', $row['missingDetails']);
    }

    public function test_importing_the_same_file_again_adds_nothing_twice_and_later_chunks_join_earlier_books(): void
    {
        $row = ['title' => 'Biology', 'authors' => 'Mader, Sylvia S.', 'callNumber' => 'Cir.362.1 an3'];
        $this->import([['row' => 2, 'accessionNumber' => '4037'] + $row])->assertJson(['books' => 1]);
        // The next chunk has another copy of the same title: it joins the book instead of making a second one.
        $this->import([['row' => 3, 'accessionNumber' => '4038'] + $row])->assertJson(['books' => 0, 'existingBooks' => 1, 'copies' => 1]);
        $this->assertSame(1, Book::where('title', 'Biology')->count());
        $this->assertSame(2, Book::where('title', 'Biology')->first()->copies()->count());

        $again = $this->import([['row' => 2, 'accessionNumber' => '4037'] + $row])->json();
        $this->assertSame(0, $again['copies']);
        $this->assertStringContainsString('already in the catalog', $again['skipped'][0]['reason']);

        // A copy the record gives no number gets the next one the first time, and is not added again the second time.
        $unnumbered = ['row' => 9, 'title' => 'System Programming Essentials With Go', 'authors' => 'Alex Rios', 'remarks' => 'ISBN:978-1-83763-413-2'];
        $this->import([$unnumbered])->assertJson(['copies' => 1, 'notes' => ['newNumbers' => 1]]);
        $second = $this->import([$unnumbered])->assertJson(['copies' => 0])->json();
        $this->assertStringContainsString('No accession number', $second['skipped'][0]['reason']);
        $this->assertSame(1, Book::where('title', 'System Programming Essentials With Go')->first()->copies()->count());
    }

    public function test_an_author_cell_that_is_not_a_name_does_not_stop_the_import(): void
    {
        // Row 2772 of the real record has a date where the author goes.
        $this->import([['row' => 2772, 'accessionNumber' => '7000', 'title' => 'Some Seminar Papers', 'authors' => 'aug 16. 2016', 'callNumber' => 'Cir.370 '],
            ['row' => 2773, 'accessionNumber' => '7001', 'title' => 'Biology', 'authors' => 'Mader, Sylvia S.']])
            ->assertOk()->assertJson(['books' => 2, 'copies' => 2]);
    }

    public function test_only_staff_with_the_library_area_can_import(): void
    {
        $assistant = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $assistant->userID, 'uuid' => Str::uuid(), 'role' => 'assistant']);

        $this->actingAs($assistant)->postJson('/api/librarian/books/import', ['rows' => [['title' => 'X']], 'dryRun' => true])->assertForbidden();
    }
}
