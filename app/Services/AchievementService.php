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
    ) {
    }

    public function listWithCounts(): Collection
    {
        return $this->achievements->withUnlockCounts();
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

        return $this->achievements->orderedByName()->map(function (Achievement $achievement) use ($unlocked) {
            $pivot = $unlocked->get($achievement->achievementID)?->pivot;

            return [
                'achievementID' => $achievement->achievementID,
                'name'          => $achievement->name,
                'pointsReward'  => $achievement->pointsReward,
                'criteria'      => $achievement->criteriaJSON,
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
     * anymore, so every Service that increases one of those two columns
     * (this service's own redeem(), MarketplaceService's refund path,
     * AttendanceService's streak update) MUST call this explicitly
     * afterward. A pure decrease (e.g. spending points) never needs this
     * call: meetsCriteria() is a ">=" check, so a smaller number can never
     * newly satisfy a threshold it didn't already satisfy.
     */
    public function evaluateForStudent(Student $student): void
    {
        $this->achievements->notYetUnlockedByStudent($student)
            ->each(function (Achievement $achievement) use ($student) {
                if ($this->meetsCriteria($achievement, $student)) {
                    $this->unlockFor($achievement, $student);
                }
            });
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
                if ($this->meetsCriteria($achievement, $student)) {
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

        return $student->{$metric} >= $threshold;
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
