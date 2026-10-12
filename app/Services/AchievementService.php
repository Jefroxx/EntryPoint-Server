<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\Student;
use App\Repositories\Contracts\AchievementRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AchievementService
{
    public function __construct(
        private AchievementRepositoryInterface $achievements,
        private StudentRepositoryInterface $students,
        private NotificationService $notifications,
        private AchievementMetrics $metrics,
    ) {
    }

    /** What a librarian can choose an achievement to be about (key, label, unit of the number). */
    public function metricCatalog(): array
    {
        return $this->metrics->catalog();
    }

    public function listWithCounts(): Collection
    {
        return $this->achievements->withUnlockCounts()->each(fn (Achievement $a) => $a->setAttribute(
            'description',
            $this->metrics->describe($a->criteriaJSON['metric'] ?? null, $a->criteriaJSON['threshold'] ?? null),
        ));
    }

    public function create(array $validated): Achievement
    {
        return DB::transaction(function () use ($validated) {
            $achievement = $this->achievements->create([
                'uuid'         => Str::uuid(),
                'name'         => $validated['name'],
                'criteriaJSON' => $validated['criteriaJSON'],
                'pointsReward' => $validated['pointsReward'],
            ]);

            $this->evaluateForAllStudents($achievement);

            return $achievement;
        });
    }

    public function update(Achievement $achievement, array $validated): Achievement
    {
        DB::transaction(function () use ($validated, $achievement) {
            $this->achievements->update($achievement, $validated);

            if (array_key_exists('criteriaJSON', $validated)) {
                $this->evaluateForAllStudents($achievement);
            }
        });

        return $achievement->fresh();
    }

    public function delete(Achievement $achievement): void
    {
        $this->achievements->delete($achievement);
    }

    public function indexForStudent(Student $student): BaseCollection
    {
        $unlocked = $this->achievements->unlockedByStudent($student)->keyBy('achievementID');
        $measured = []; // each metric is counted once per request, however many achievements use it

        return $this->achievements->orderedByName()->map(function (Achievement $achievement) use ($unlocked, $student, &$measured) {
            $pivot = $unlocked->get($achievement->achievementID)?->pivot;
            $metric = $achievement->criteriaJSON['metric'] ?? null;
            $threshold = (int) ($achievement->criteriaJSON['threshold'] ?? 0);
            $current = $metric ? ($measured[$metric] ??= $this->metrics->value($metric, $student)) : 0;

            return [
                'achievementID' => $achievement->achievementID,
                'uuid'          => $achievement->uuid,
                'name'          => $achievement->name,
                'pointsReward'  => $achievement->pointsReward,
                'criteria'      => $achievement->criteriaJSON,
                // In plain words ("Borrow 5 books"), and how far along the student is.
                'description'   => $this->metrics->describe($metric, $threshold),
                'progress'      => ['current' => $current, 'target' => $threshold],
                'status'        => match (true) {
                    $pivot && $pivot->redeemedAt => 'Redeemed',
                    (bool) $pivot                => 'Unlocked',
                    default                      => 'Locked',
                },
                'earnedAt'   => $pivot->earnedAt ?? null,
                'redeemedAt' => $pivot->redeemedAt ?? null,
            ];
        });
    }

    public function redeem(Achievement $achievement, Student $student): void
    {
        $pivot = $this->achievements->pivotForStudent($achievement, $student);

        if (! $pivot) {
            throw ValidationException::withMessages([
                'achievement' => ["You haven't unlocked this achievement yet."],
            ]);
        }

        if ($pivot->redeemedAt) {
            throw ValidationException::withMessages([
                'achievement' => ['This achievement has already been redeemed.'],
            ]);
        }

        DB::transaction(function () use ($achievement, $student) {
            $this->achievements->markRedeemed($achievement, $student->studentID);
            $this->students->creditPoints($student, $achievement->pointsReward);
        });

        // Redeeming grants points, which can itself cross the threshold for
        // another achievement (e.g. a points-based one) — re-check.
        $this->evaluateForStudent($student);

        $this->notifications->send(
            $student->studentID,
            "You've redeemed \"{$achievement->name}\" for {$achievement->pointsReward} points!",
            'achievement_redeemed'
        );
    }

    /**
     * Re-checks every achievement this student hasn't unlocked yet and
     * unlocks any whose criteria are now met. This replaces the old
     * Student::booted() model-event hook that fired automatically whenever
     * knowledgeScore/visitStreak changed — there is no implicit trigger
     * anymore, so every Service that changes a number an achievement counts
     * (this service's own redeem(), MarketplaceService's refund path and
     * redemptions, AttendanceService's visits, CirculationService's checkouts
     * and returns, BookSuggestionService's approvals) MUST call this
     * explicitly afterward. A pure decrease (e.g. spending points) never needs this
     * call: meetsCriteria() is a ">=" check, so a smaller number can never
     * newly satisfy a threshold it didn't already satisfy.
     */
    public function evaluateForStudent(Student $student): void
    {
        // Achievements are for students; faculty and staff members never unlock them.
        if (! $student->takesPartInEngagement()) {
            return;
        }

        $this->achievements->notYetUnlockedByStudent($student)
            ->each(function (Achievement $achievement) use ($student) {
                if ($this->meetsCriteria($achievement, $student)) {
                    $this->unlockFor($achievement, $student);
                }
            });
    }

    /**
     * Re-checks every approved student against every achievement they haven't unlocked. For after achievements are
     * added in bulk or the data changed outside the app; day to day each event re-checks its own student.
     *
     * @return int how many achievements were newly unlocked
     */
    public function evaluateForEveryone(): int
    {
        $before = DB::table('student_achievement')->count();

        Student::where('registrationStatus', 'approved')->where('memberType', 'student')->each(fn (Student $student) => $this->evaluateForStudent($student));

        return DB::table('student_achievement')->count() - $before;
    }

    /**
     * Called after an achievement is created/edited — retroactively unlocks
     * it for any student who already qualifies under the (possibly new)
     * criteria, so a librarian adding "reach 50 points" doesn't leave
     * already-qualifying students stuck waiting for their next point change.
     */
    private function evaluateForAllStudents(Achievement $achievement): void
    {
        $this->students->notHavingAchievement($achievement->achievementID)
            ->each(function (Student $student) use ($achievement) {
                if ($student->takesPartInEngagement() && $this->meetsCriteria($achievement, $student)) {
                    $this->unlockFor($achievement, $student);
                }
            });
    }

    private function meetsCriteria(Achievement $achievement, Student $student): bool
    {
        $metric = $achievement->criteriaJSON['metric'] ?? null;
        $threshold = $achievement->criteriaJSON['threshold'] ?? null;

        if (! in_array($metric, Achievement::CRITERIA_METRICS, true) || $threshold === null) {
            return false;
        }

        return $this->metrics->value($metric, $student) >= $threshold;
    }

    /**
     * Unlock this achievement for a student (idempotent). This only records
     * that it's earned and notifies the student — points aren't granted
     * until the student redeems it separately.
     */
    private function unlockFor(Achievement $achievement, Student $student): void
    {
        if ($this->achievements->pivotForStudent($achievement, $student)) {
            return;
        }

        $this->achievements->attachToStudent($achievement, $student, [
            'uuid'         => Str::uuid(),
            'triggerEvent' => $achievement->criteriaJSON['metric'] ?? null,
            'earnedAt'     => now(),
        ]);

        $this->notifications->send(
            $student->studentID,
            "You've unlocked the \"{$achievement->name}\" achievement! Redeem it in your Achievements tab to claim {$achievement->pointsReward} points.",
            'achievement_unlocked'
        );
    }
}
