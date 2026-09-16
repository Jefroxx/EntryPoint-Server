<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Achievement extends Model
{
    use HasFactory;

    protected $primaryKey = 'achievementID';

    protected $fillable = ['uuid', 'name', 'criteriaJSON', 'pointsReward'];

    protected $casts = ['criteriaJSON' => 'array'];

    // criteriaJSON is evaluated as a simple {metric, threshold} rule against
    // one of these Student columns — extend this list as new gamification
    // metrics are added elsewhere in the app.
    public const CRITERIA_METRICS = ['knowledgeScore', 'visitStreak'];

    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_achievement', 'achievementID', 'studentID')
            ->withPivot(['triggerEvent', 'earnedAt', 'redeemedAt']);
    }

    public function meetsCriteria(Student $student): bool
    {
        $metric = $this->criteriaJSON['metric'] ?? null;
        $threshold = $this->criteriaJSON['threshold'] ?? null;

        if (! in_array($metric, self::CRITERIA_METRICS, true) || $threshold === null) {
            return false;
        }

        return $student->{$metric} >= $threshold;
    }

    /**
     * Unlock this achievement for a student (idempotent). This only records
     * that it's earned and notifies the student — points aren't granted
     * until the student redeems it separately.
     */
    public function unlockFor(Student $student): void
    {
        $alreadyUnlocked = $this->students()->where('students.studentID', $student->studentID)->exists();

        if ($alreadyUnlocked) {
            return;
        }

        $this->students()->attach($student->studentID, [
            'uuid'         => Str::uuid(),
            'triggerEvent' => $this->criteriaJSON['metric'] ?? null,
            'earnedAt'     => now(),
        ]);

        SystemNotification::notify(
            $student->studentID,
            "You've unlocked the \"{$this->name}\" achievement! Redeem it in your Achievements tab to claim {$this->pointsReward} points.",
            'achievement_unlocked'
        );
    }

    /**
     * Called whenever a student's gamification metrics change (see
     * Student::booted()) — checks every achievement they haven't already
     * unlocked and unlocks any whose criteria are now met.
     */
    public static function evaluateForStudent(Student $student): void
    {
        static::whereDoesntHave('students', fn ($q) => $q->where('students.studentID', $student->studentID))
            ->get()
            ->each(function (self $achievement) use ($student) {
                if ($achievement->meetsCriteria($student)) {
                    $achievement->unlockFor($student);
                }
            });
    }

    /**
     * Called after an achievement is created/edited — retroactively unlocks
     * it for any student who already qualifies under the (possibly new)
     * criteria, so a librarian adding "reach 50 points" doesn't leave
     * already-qualifying students stuck waiting for their next point change.
     */
    public function evaluateForAllStudents(): void
    {
        Student::whereDoesntHave('achievements', fn ($q) => $q->where('achievements.achievementID', $this->achievementID))
            ->get()
            ->each(function (Student $student) {
                if ($this->meetsCriteria($student)) {
                    $this->unlockFor($student);
                }
            });
    }
}
