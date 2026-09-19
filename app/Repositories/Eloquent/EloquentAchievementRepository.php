<?php

namespace App\Repositories\Eloquent;

use App\Models\Achievement;
use App\Models\Student;
use App\Repositories\Contracts\AchievementRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class EloquentAchievementRepository extends BaseRepository implements AchievementRepositoryInterface
{
    public function __construct(Achievement $model)
    {
        parent::__construct($model);
    }

    public function withUnlockCounts(): Collection
    {
        // wherePivotNotNull() doesn't resolve correctly inside a withCount()
        // aggregate subquery closure (loses the pivot-table context) —
        // reference the pivot table's column directly instead.
        return Achievement::withCount([
            'students as unlockedCount',
            'students as redeemedCount' => fn ($q) => $q->whereNotNull('student_achievement.redeemedAt'),
        ])->orderBy('name')->get();
    }

    public function orderedByName(): Collection
    {
        return Achievement::orderBy('name')->get();
    }

    public function unlockedByStudent(Student $student): Collection
    {
        return $student->achievements()->get();
    }

    public function pivotForStudent(Achievement $achievement, Student $student): ?object
    {
        return $achievement->students()->where('students.studentID', $student->studentID)->first()?->pivot;
    }

    public function markRedeemed(Achievement $achievement, int $studentID): void
    {
        $achievement->students()->updateExistingPivot($studentID, ['redeemedAt' => now()]);
    }

    public function notYetUnlockedByStudent(Student $student): Collection
    {
        return Achievement::whereDoesntHave(
            'students',
            fn ($query) => $query->where('students.studentID', $student->studentID)
        )->get();
    }

    public function attachToStudent(Achievement $achievement, Student $student, array $pivotData): void
    {
        $achievement->students()->attach($student->studentID, $pivotData);
    }
}
