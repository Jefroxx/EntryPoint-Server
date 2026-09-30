<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Hall of Fame on the student Home page and the librarian Dashboard: the top three students in a
 * handful of categories, so the ones who use the library well get seen. Most categories cover the
 * current month and start over on the 1st, so a new student can reach the top; streaks and
 * achievements run longer.
 *
 * Only approved students are ranked and a student needs more than zero to place. Students see names
 * as "First L." with a program; librarians see full names and ID numbers. Ties share a place.
 */
class HallOfFameService
{
    /** The boards change slowly, and every student's Home page asks for them. */
    private const CACHE_SECONDS = 300;

    private const LEADERS = 3;

    /**
     * The student view: names as "First L." and program, the viewer's own rows flagged, and their own
     * number in every category. No IDs of other students leave the server.
     *
     * @return array{month: string, categories: array<int, array<string, mixed>>}
     */
    public function board(int $viewerStudentID): array
    {
        return $this->present(fn (array $category, array $leaders) => [
            'leaders' => array_map(fn (array $leader) => [
                'rank'     => $leader['rank'],
                'name'     => trim($leader['firstName'] . ' ' . mb_substr($leader['lastName'], 0, 1) . '.'),
                'initials' => $this->initials($leader),
                'program'  => $leader['program'],
                'value'    => $leader['value'],
                'isYou'    => $leader['studentID'] === $viewerStudentID,
            ], $leaders),
            'yourValue' => $this->valueFor($category['rows'], $viewerStudentID),
        ]);
    }

    /**
     * The librarian Dashboard view: the same boards with full names and student ID numbers,
     * since librarians already see every student's record.
     *
     * @return array{month: string, categories: array<int, array<string, mixed>>}
     */
    public function staffBoard(): array
    {
        return $this->present(fn (array $category, array $leaders) => [
            'leaders' => array_map(fn (array $leader) => [
                'rank'            => $leader['rank'],
                'name'            => trim($leader['firstName'] . ' ' . $leader['lastName']),
                'initials'        => $this->initials($leader),
                'program'         => $leader['program'],
                'studentIDNumber' => $leader['studentIDNumber'],
                'value'           => $leader['value'],
                'isYou'           => false,
            ], $leaders),
        ]);
    }

    /**
     * Works the rankings out once (cached, shared by everyone who asks), then lets each audience
     * shape the names and extras it's allowed to see.
     *
     * @param  callable(array $category, array $leaders): array  $shape
     */
    private function present(callable $shape): array
    {
        $start = now()->startOfMonth();
        $categories = $this->categories($start, now()->endOfMonth());

        $leaders = Cache::remember('hall-of-fame:v2:' . $start->format('Y-m'), self::CACHE_SECONDS, fn () => $categories
            ->mapWithKeys(fn (array $category) => [$category['key'] => $this->leaders($category['rows'])->all()])
            ->all());

        return [
            'month'      => $start->format('F Y'),
            'categories' => $categories->map(fn (array $category) => [
                ...collect($category)->except('rows')->all(),
                ...$shape($category, $leaders[$category['key']] ?? []),
            ])->values()->all(),
        ];
    }

    private function initials(array $leader): string
    {
        return mb_strtoupper(mb_substr($leader['firstName'], 0, 1) . mb_substr($leader['lastName'], 0, 1));
    }

    /**
     * Each category is a query yielding (studentID, value) rows.
     */
    private function categories(Carbon $start, Carbon $end): Collection
    {
        $inMonth = fn (string $column) => fn (Builder $query) => $query->whereBetween($column, [$start, $end]);

        return collect([
            [
                'key'    => 'most-active',
                'title'  => 'Most Active',
                'blurb'  => 'Came to the library on the most days',
                'icon'   => 'i-tabler-walk',
                'unit'   => 'day',
                'period' => 'month',
                // Days, not check-ins: scanning in and out five times in one afternoon counts once.
                'rows'   => DB::table('attendance_logs')->tap($inMonth('entryTime'))
                    ->groupBy('studentID')
                    ->selectRaw('studentID, COUNT(DISTINCT DATE(entryTime)) AS value'),
            ],
            [
                'key'    => 'top-reader',
                'title'  => 'Top Reader',
                'blurb'  => 'Borrowed the most books',
                'icon'   => 'i-tabler-books',
                'unit'   => 'book',
                'period' => 'month',
                'rows'   => DB::table('loans')->tap($inMonth('checkoutDate'))
                    ->groupBy('studentID')
                    ->selectRaw('studentID, COUNT(*) AS value'),
            ],
            [
                'key'    => 'study-champion',
                'title'  => 'Study Champion',
                'blurb'  => 'Spent the most hours in the library',
                'icon'   => 'i-tabler-clock-hour-4',
                'unit'   => 'hour',
                'period' => 'month',
                // Only visits that were checked out of; an open visit has no length yet.
                'rows'   => DB::table('attendance_logs')->tap($inMonth('entryTime'))
                    ->whereNotNull('exitTime')
                    ->groupBy('studentID')
                    ->selectRaw('studentID, ROUND(SUM(TIMESTAMPDIFF(MINUTE, entryTime, exitTime)) / 60, 1) AS value'),
            ],
            [
                'key'    => 'always-on-time',
                'title'  => 'Always On Time',
                'blurb'  => 'Returned the most books by their due date',
                'icon'   => 'i-tabler-calendar-check',
                'unit'   => 'book',
                'period' => 'month',
                'rows'   => DB::table('loans')->tap($inMonth('returnDate'))
                    ->whereColumn('returnDate', '<=', 'dueDate')
                    ->groupBy('studentID')
                    ->selectRaw('studentID, COUNT(*) AS value'),
            ],
            [
                'key'    => 'longest-streak',
                'title'  => 'Longest Streak',
                'blurb'  => 'Visited on the most days in a row',
                'icon'   => 'i-tabler-flame',
                'unit'   => 'day',
                'period' => 'now',
                'rows'   => DB::table('students')->selectRaw('studentID, visitStreak AS value'),
            ],
            [
                'key'    => 'achievement-hunter',
                'title'  => 'Achievement Hunter',
                'blurb'  => 'Unlocked the most achievements',
                'icon'   => 'i-tabler-trophy',
                'unit'   => 'achievement',
                'period' => 'all',
                'rows'   => DB::table('student_achievement')
                    ->groupBy('studentID')
                    ->selectRaw('studentID, COUNT(*) AS value'),
            ],
        ]);
    }

    private function leaders(Builder $rows): Collection
    {
        return DB::query()->fromSub($rows, 'r')
            ->join('students as s', 's.studentID', '=', 'r.studentID')
            ->join('users as u', 'u.userID', '=', 's.studentID')
            ->where('s.registrationStatus', 'approved')
            ->where('r.value', '>', 0)
            ->orderByDesc('r.value')
            ->orderBy('r.studentID')
            ->limit(self::LEADERS)
            ->get(['r.studentID', 'r.value', 'u.firstName', 'u.lastName', 's.academicProgram', 's.studentIDNumber'])
            ->values()
            // Equal numbers share a place (3, 3, 2 → 1st, 1st, 3rd); the listing order still breaks the tie.
            ->pipe(function (Collection $rows) {
                $rank = 0;
                $previous = null;

                return $rows->map(function ($row, int $index) use (&$rank, &$previous) {
                    if ((float) $row->value !== $previous) {
                        $rank = $index + 1;
                        $previous = (float) $row->value;
                    }
                    $row->rank = $rank;

                    return $row;
                });
            })
            // Raw details; board() and staffBoard() decide how much of the name each audience sees.
            ->map(fn ($row) => [
                'studentID'       => (int) $row->studentID,
                'rank'            => $row->rank,
                'firstName'       => (string) $row->firstName,
                'lastName'        => (string) $row->lastName,
                'studentIDNumber' => $row->studentIDNumber,
                'program'         => $row->academicProgram,
                'value'           => (float) $row->value,
            ]);
    }

    /** The viewer's own number in a category, so a student who isn't on the board still sees where they stand. */
    private function valueFor(Builder $rows, int $studentID): float
    {
        return (float) (DB::query()->fromSub($rows, 'r')->where('r.studentID', $studentID)->value('r.value') ?? 0);
    }
}
