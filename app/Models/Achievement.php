<?php

namespace App\Models;

use App\Models\Concerns\UsesUuidRouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    use HasFactory, UsesUuidRouteKey;

    protected $primaryKey = 'achievementID';

    protected $fillable = ['uuid', 'name', 'criteriaJSON', 'pointsReward'];

    protected $casts = ['criteriaJSON' => 'array'];

    // criteriaJSON is a simple {metric, threshold} rule: it unlocks once the student's number for that metric
    // reaches the threshold. What each metric is, and how it is measured, lives in AchievementMetrics.
    public const CRITERIA_METRICS = \App\Services\AchievementMetrics::KEYS;

    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_achievement', 'achievementID', 'studentID')
            ->withPivot(['triggerEvent', 'earnedAt', 'redeemedAt']);
    }
}
