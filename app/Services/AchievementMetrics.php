<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Everything an achievement can be about. An achievement is "metric reaches threshold", and this is the list of
 * metrics: what each is called, how a goal reads in plain words, and how it is measured for one student.
 *
 * Every metric is counted from records the library already keeps (loans, visits, reservations, suggestions and
 * redemptions), so adding one means adding a line here and re-checking achievements after the event that changes it
 * (see AchievementService::evaluateForStudent()).
 */
class AchievementMetrics
{
    /** The keys an achievement's criteria may use. */
    public const KEYS = [
        'knowledgeScore',
        'visitStreak',
        'booksBorrowed',
        'onTimeReturns',
        'onTimeReturnStreak',
        'daysVisited',
        'genresExplored',
        'reservationsPickedUp',
        'suggestionsApproved',
        'rewardsRedeemed',
    ];

    /**
     * label: the sentence a librarian picks from ("Unlocks when ...").  unit: what the number counts.
     * goal: how a goal reads to a student, with {n} for the threshold.  one: the same for exactly one.
     *
     * @return array<string, array{label: string, unit: string, goal: string, one: string}>
     */
    public static function definitions(): array
    {
        return [
            'knowledgeScore' => [
                'label' => 'Knowledge score reaches', 'unit' => 'Points',
                'goal' => 'Reach {n} knowledge points', 'one' => 'Reach 1 knowledge point',
            ],
            'visitStreak' => [
                'label' => 'Visit streak reaches', 'unit' => 'Days',
                'goal' => 'Visit the library {n} days in a row', 'one' => 'Visit the library',
            ],
            'booksBorrowed' => [
                'label' => 'Books borrowed reaches', 'unit' => 'Books',
                'goal' => 'Borrow {n} books', 'one' => 'Borrow your first book',
            ],
            'onTimeReturns' => [
                'label' => 'Books returned on time reaches', 'unit' => 'Books',
                'goal' => 'Return {n} books on or before their due date', 'one' => 'Return a book on or before its due date',
            ],
            'onTimeReturnStreak' => [
                'label' => 'On-time returns in a row reaches', 'unit' => 'Returns',
                'goal' => 'Return {n} books on time in a row', 'one' => 'Return a book on time',
            ],
            'daysVisited' => [
                'label' => 'Different days visited reaches', 'unit' => 'Days',
                'goal' => 'Visit the library on {n} different days', 'one' => 'Visit the library',
            ],
            'genresExplored' => [
                'label' => 'Genres borrowed from reaches', 'unit' => 'Genres',
                'goal' => 'Borrow books from {n} different genres', 'one' => 'Borrow a book',
            ],
            'reservationsPickedUp' => [
                'label' => 'Reserved books picked up reaches', 'unit' => 'Books',
                'goal' => 'Reserve and pick up {n} books', 'one' => 'Reserve a book and pick it up',
            ],
            'suggestionsApproved' => [
                'label' => 'Book suggestions approved reaches', 'unit' => 'Suggestions',
                'goal' => 'Get {n} of your book suggestions approved', 'one' => 'Get a book suggestion approved',
            ],
            'rewardsRedeemed' => [
                'label' => 'Rewards redeemed reaches', 'unit' => 'Rewards',
                'goal' => 'Redeem {n} rewards from the shop', 'one' => 'Redeem a reward from the shop',
            ],
        ];
    }

    /** What a librarian chooses from when making an achievement: key, label and the unit of its number. */
    public function catalog(): array
    {
        $out = [];
        foreach (self::definitions() as $key => $d) {
            // goal / one are the student-facing wording ({n} is the number), so a form can show the goal as students read it.
            $out[] = ['key' => $key, 'label' => $d['label'], 'unit' => $d['unit'], 'goal' => $d['goal'], 'one' => $d['one']];
        }

        return $out;
    }

    /** "Borrow 5 books": the goal in plain words. */
    public function describe(?string $metric, ?int $threshold): string
    {
        $d = self::definitions()[$metric] ?? null;

        if (! $d || ! $threshold) {
            return 'Keep using the library to unlock it';
        }

        return $threshold === 1 ? $d['one'] : str_replace('{n}', (string) $threshold, $d['goal']);
    }

    /** This student's number for the metric. 0 for a metric that doesn't exist. */
    public function value(string $metric, Student $student): int
    {
        $id = $student->studentID;

        return match ($metric) {
            'knowledgeScore' => (int) $student->knowledgeScore,
            'visitStreak'    => (int) $student->visitStreak,

            'booksBorrowed' => DB::table('loans')->where('studentID', $id)->count(),

            'onTimeReturns' => DB::table('loans')
                ->where('studentID', $id)->where('status', 'Returned')
                ->whereColumn('returnDate', '<=', 'dueDate')
                ->count(),

            'onTimeReturnStreak' => $this->onTimeStreak($id),

            // One count per calendar day, however many times they scanned in.
            'daysVisited' => (int) DB::table('attendance_logs')
                ->where('studentID', $id)
                ->selectRaw('COUNT(DISTINCT DATE(entryTime)) AS days')
                ->value('days'),

            'genresExplored' => (int) DB::table('loans')
                ->join('book_copies', 'book_copies.copyID', '=', 'loans.copyID')
                ->join('books', 'books.bookID', '=', 'book_copies.bookID')
                ->where('loans.studentID', $id)->whereNotNull('books.subjectID')
                ->selectRaw('COUNT(DISTINCT books.subjectID) AS genres')
                ->value('genres'),

            'reservationsPickedUp' => DB::table('reservations')->where('studentID', $id)->where('status', 'Fulfilled')->count(),

            'suggestionsApproved' => DB::table('book_suggestions')->where('studentID', $id)->where('status', 'Approved')->count(),

            // A cancelled redemption was refunded, so it doesn't count as a reward the student got.
            'rewardsRedeemed' => DB::table('point_redemptions')->where('studentID', $id)->where('fulfillmentStatus', '!=', 'Cancelled')->count(),

            default => 0,
        };
    }

    /** The newest returns first, counting how many in a row were on or before their due date. */
    private function onTimeStreak(int $studentID): int
    {
        $streak = 0;

        $returns = DB::table('loans')
            ->where('studentID', $studentID)->where('status', 'Returned')->whereNotNull('returnDate')
            ->orderByDesc('returnDate')->orderByDesc('loanID')
            ->limit(500)
            ->get(['returnDate', 'dueDate']);

        foreach ($returns as $loan) {
            if ($loan->returnDate > $loan->dueDate) {
                break;
            }
            $streak++;
        }

        return $streak;
    }
}
