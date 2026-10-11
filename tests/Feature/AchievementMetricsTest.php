<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\AttendanceLog;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookSubject;
use App\Models\BookSuggestion;
use App\Models\Librarian;
use App\Models\Loan;
use App\Models\LoanPeriod;
use App\Models\MarketItem;
use App\Models\PointRedemption;
use App\Models\Reservation;
use App\Models\Student;
use App\Models\User;
use App\Services\AchievementMetrics;
use App\Services\AchievementService;
use App\Services\BookSuggestionService;
use App\Services\MarketplaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Achievements can be about more than points and streaks: borrowing, returning on time, exploring genres, coming
 * in on different days, reserving, suggesting books and using the reward shop.
 */
class AchievementMetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $librarian;
    private Student $student;
    private AchievementMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        BookSubject::factory()->count(3)->create();
        $this->librarian = User::factory()->create(['userType' => 'librarian']);
        Librarian::create(['librarianID' => $this->librarian->userID, 'uuid' => Str::uuid(), 'role' => 'librarian']);

        $this->student = Student::factory()->approved()->create(['knowledgeScore' => 0, 'visitStreak' => 0]);
        $this->metrics = app(AchievementMetrics::class);
    }

    private function loan(?Book $book = null, string $status = 'Returned', ?\DateTimeInterface $due = null, ?\DateTimeInterface $returned = null): Loan
    {
        $book ??= Book::factory()->create();
        $copy = BookCopy::factory()->create(['bookID' => $book->bookID, 'status' => $status === 'Active' ? 'borrowed' : 'available']);
        $due ??= now()->subDays(5);

        return Loan::create([
            'uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'copyID' => $copy->copyID, 'loanType' => 'circulation',
            'checkoutDate' => (clone $due)->modify('-7 days'), 'dueDate' => $due,
            'returnDate' => $status === 'Returned' ? ($returned ?? (clone $due)->modify('-1 day')) : null, 'status' => $status,
        ]);
    }

    private function value(string $metric): int
    {
        return $this->metrics->value($metric, $this->student->fresh());
    }

    public function test_books_borrowed_counts_every_loan_returned_or_not(): void
    {
        $this->loan(status: 'Returned');
        $this->loan(status: 'Returned');
        $this->loan(status: 'Active');

        $this->assertSame(3, $this->value('booksBorrowed'));
    }

    public function test_on_time_returns_counts_only_books_back_by_their_due_date(): void
    {
        $this->loan(due: now()->subDays(10), returned: now()->subDays(11));          // early
        $this->loan(due: now()->subDays(10), returned: now()->subDays(10));          // exactly on time
        $this->loan(due: now()->subDays(10), returned: now()->subDays(8));           // late
        $this->loan(status: 'Active');                                               // not returned

        $this->assertSame(2, $this->value('onTimeReturns'));
    }

    public function test_the_on_time_streak_counts_back_from_the_latest_return_until_a_late_one(): void
    {
        $this->loan(due: now()->subDays(30), returned: now()->subDays(25));  // late, oldest
        $this->loan(due: now()->subDays(20), returned: now()->subDays(21));  // on time
        $this->loan(due: now()->subDays(10), returned: now()->subDays(11));  // on time
        $this->assertSame(2, $this->value('onTimeReturnStreak'));

        $this->loan(due: now()->subDays(3), returned: now()->subDay());      // the latest is late: the run breaks
        $this->assertSame(0, $this->value('onTimeReturnStreak'));
    }

    public function test_days_visited_counts_each_calendar_day_once(): void
    {
        foreach (['2026-10-01 09:00:00', '2026-10-01 15:30:00', '2026-10-02 10:00:00', '2026-10-05 08:00:00'] as $entry) {
            AttendanceLog::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'entryTime' => $entry]);
        }

        $this->assertSame(3, $this->value('daysVisited'));
    }

    public function test_genres_explored_counts_different_subjects_borrowed_from(): void
    {
        [$a, $b] = BookSubject::pluck('subjectID')->all();
        $this->loan(Book::factory()->create(['subjectID' => $a]));
        $this->loan(Book::factory()->create(['subjectID' => $a]));
        $this->loan(Book::factory()->create(['subjectID' => $b]));

        $this->assertSame(2, $this->value('genresExplored'));
    }

    public function test_reserved_books_picked_up_counts_only_fulfilled_reservations(): void
    {
        $book = Book::factory()->create();
        foreach (['Fulfilled', 'Fulfilled', 'Accepted', 'Waiting', 'Rejected'] as $status) {
            Reservation::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'bookID' => $book->bookID, 'status' => $status, 'reservedAt' => now()]);
        }

        $this->assertSame(2, $this->value('reservationsPickedUp'));
    }

    public function test_suggestions_approved_ignores_pending_and_rejected_ones(): void
    {
        foreach (['Approved', 'Approved', 'Pending', 'Rejected'] as $status) {
            BookSuggestion::factory()->create(['studentID' => $this->student->studentID, 'status' => $status]);
        }

        $this->assertSame(2, $this->value('suggestionsApproved'));
    }

    public function test_rewards_redeemed_leaves_out_cancelled_ones(): void
    {
        $item = MarketItem::factory()->create();
        foreach (['Pending', 'Fulfilled', 'Cancelled'] as $status) {
            PointRedemption::create(['uuid' => Str::uuid(), 'studentID' => $this->student->studentID, 'itemID' => $item->itemID, 'quantity' => 1, 'pointsSpent' => 10, 'fulfillmentStatus' => $status, 'redeemedAt' => now()]);
        }

        $this->assertSame(2, $this->value('rewardsRedeemed'));
    }

    public function test_the_points_and_streak_metrics_still_read_the_student_columns(): void
    {
        $this->student->update(['knowledgeScore' => 120, 'visitStreak' => 6]);

        $this->assertSame(120, $this->value('knowledgeScore'));
        $this->assertSame(6, $this->value('visitStreak'));
    }

    // ---- the achievements themselves ----

    private function achievement(string $metric, int $threshold): Achievement
    {
        return Achievement::create(['uuid' => Str::uuid(), 'name' => "{$metric} {$threshold}", 'criteriaJSON' => ['metric' => $metric, 'threshold' => $threshold], 'pointsReward' => 10]);
    }

    private function unlocked(Achievement $achievement): bool
    {
        return $this->student->achievements()->where('student_achievement.achievementID', $achievement->achievementID)->exists();
    }

    public function test_borrowing_a_book_at_the_desk_unlocks_a_books_borrowed_achievement(): void
    {
        $first = $this->achievement('booksBorrowed', 1);
        // A collection that can be loaned out (the factory picks one at random).
        LoanPeriod::updateOrCreate(['area' => 'circulation'], ['loanable' => true, 'periodValue' => 3, 'periodUnit' => 'days']);
        $copy = BookCopy::factory()->create(['bookID' => Book::factory()->create(['areaOfLibrary' => 'circulation'])->bookID]);
        $this->assertFalse($this->unlocked($first));

        $this->actingAs($this->librarian)->postJson('/api/librarian/loans', ['studentID' => $this->student->studentID, 'copyID' => $copy->copyID])->assertCreated();

        $this->assertTrue($this->unlocked($first));
    }

    public function test_returning_a_book_on_time_unlocks_an_on_time_achievement(): void
    {
        $punctual = $this->achievement('onTimeReturns', 1);
        $loan = $this->loan(status: 'Active', due: now()->addDays(2));
        $this->assertFalse($this->unlocked($punctual));

        $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loan->uuid}/return")->assertOk();

        $this->assertTrue($this->unlocked($punctual));
    }

    public function test_a_late_return_does_not_unlock_an_on_time_achievement(): void
    {
        $punctual = $this->achievement('onTimeReturns', 1);
        $loan = $this->loan(status: 'Active', due: now()->subDays(2));

        $this->actingAs($this->librarian)->postJson("/api/librarian/loans/{$loan->uuid}/return")->assertOk();

        $this->assertFalse($this->unlocked($punctual));
    }

    public function test_an_approved_suggestion_unlocks_a_suggestion_achievement(): void
    {
        $scout = $this->achievement('suggestionsApproved', 1);
        $suggestion = BookSuggestion::factory()->create(['studentID' => $this->student->studentID, 'status' => 'Pending']);

        app(BookSuggestionService::class)->approve($suggestion, $this->librarian->librarian->librarianID);

        $this->assertTrue($this->unlocked($scout));
    }

    public function test_redeeming_a_reward_unlocks_a_shopper_achievement(): void
    {
        $shopper = $this->achievement('rewardsRedeemed', 1);
        $this->student->update(['knowledgeScore' => 500]);
        $item = MarketItem::factory()->create(['pointCost' => 5, 'stock' => 3]);

        app(MarketplaceService::class)->redeemSingle($item, $this->student->fresh());

        $this->assertTrue($this->unlocked($shopper));
    }

    public function test_a_new_achievement_unlocks_at_once_for_students_who_already_qualify(): void
    {
        $this->loan();
        $this->loan();

        $response = $this->actingAs($this->librarian)->postJson('/api/librarian/achievements', [
            'name' => 'Bookworm', 'criteriaJSON' => ['metric' => 'booksBorrowed', 'threshold' => 2], 'pointsReward' => 25,
        ])->assertCreated();

        $this->assertTrue($this->student->achievements()->where('name', 'Bookworm')->exists());
    }

    public function test_the_librarian_list_has_descriptions_and_the_metric_choices(): void
    {
        $this->achievement('genresExplored', 3);

        $this->actingAs($this->librarian)->getJson('/api/librarian/achievements')
            ->assertOk()
            ->assertJsonPath('achievements.0.description', 'Borrow books from 3 different genres')
            ->assertJsonCount(count(AchievementMetrics::KEYS), 'metrics')
            ->assertJsonFragment(['key' => 'onTimeReturnStreak', 'unit' => 'Returns']);
    }

    public function test_an_unknown_metric_is_refused(): void
    {
        $this->actingAs($this->librarian)->postJson('/api/librarian/achievements', [
            'name' => 'Nope', 'criteriaJSON' => ['metric' => 'shoeSize', 'threshold' => 5], 'pointsReward' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('criteriaJSON.metric');
    }

    public function test_a_student_sees_each_goal_in_words_with_their_progress(): void
    {
        $this->achievement('booksBorrowed', 5);
        $this->achievement('daysVisited', 1);
        $this->loan();
        $this->loan();

        $rows = collect($this->actingAs($this->student->user)->getJson('/api/student/achievements')->assertOk()->json('achievements'))->keyBy('name');

        $this->assertSame('Borrow 5 books', $rows['booksBorrowed 5']['description']);
        $this->assertSame(['current' => 2, 'target' => 5], $rows['booksBorrowed 5']['progress']);
        $this->assertSame('Visit the library', $rows['daysVisited 1']['description']);
        $this->assertSame(['current' => 0, 'target' => 1], $rows['daysVisited 1']['progress']);
        $this->assertSame('Locked', $rows['booksBorrowed 5']['status']);
    }

    public function test_the_evaluate_command_unlocks_what_students_already_earned(): void
    {
        $this->loan();
        $this->loan();
        $achievement = $this->achievement('booksBorrowed', 2);
        $this->assertFalse($this->unlocked($achievement));

        $this->artisan('achievements:evaluate')->expectsOutputToContain('Unlocked 1 achievement')->assertSuccessful();

        $this->assertTrue($this->unlocked($achievement));
        // Running it again changes nothing.
        $this->artisan('achievements:evaluate')->expectsOutputToContain('Unlocked 0 achievement')->assertSuccessful();
    }
}
