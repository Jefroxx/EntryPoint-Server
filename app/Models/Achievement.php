<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
