<?php

namespace App\Repositories\Contracts;

use App\Models\Achievement;
use App\Models\Student;
use Illuminate\Database\Eloquent\Collection;

interface AchievementRepositoryInterface extends RepositoryInterface
{
    public function withUnlockCounts(): Collection;

    public function orderedByName(): Collection;

    public function unlockedByStudent(Student $student): Collection;

    public function pivotForStudent(Achievement $achievement, Student $student): ?object;

    public function markRedeemed(Achievement $achievement, int $studentID): void;

    public function notYetUnlockedByStudent(Student $student): Collection;

    public function attachToStudent(Achievement $achievement, Student $student, array $pivotData): void;
}
