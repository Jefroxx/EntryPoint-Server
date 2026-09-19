<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AchievementController extends Controller
{
    public function index(Request $request)
    {
        $student = $request->user()->student;

        $unlocked = $student->achievements()->get()->keyBy('achievementID');

        $achievements = Achievement::orderBy('name')->get()->map(function (Achievement $achievement) use ($unlocked) {
            $pivot = $unlocked->get($achievement->achievementID)?->pivot;

            return [
                'achievementID' => $achievement->achievementID,
                'name'          => $achievement->name,
                'pointsReward'  => $achievement->pointsReward,
                // {metric, threshold}: lets the app show progress toward a locked achievement
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

        return response()->json(['achievements' => $achievements]);
    }

    public function redeem(Request $request, Achievement $achievement)
    {
        $student = $request->user()->student;

        $pivot = $achievement->students()->where('students.studentID', $student->studentID)->first()?->pivot;

        if (! $pivot) {
            return response()->json(['message' => "You haven't unlocked this achievement yet."], 422);
        }

        if ($pivot->redeemedAt) {
            return response()->json(['message' => 'This achievement has already been redeemed.'], 422);
        }

        DB::transaction(function () use ($achievement, $student) {
            $achievement->students()->updateExistingPivot($student->studentID, ['redeemedAt' => now()]);

            $student->update(['knowledgeScore' => $student->knowledgeScore + $achievement->pointsReward]);
        });

        SystemNotification::notify(
            $student->studentID,
            "You've redeemed \"{$achievement->name}\" for {$achievement->pointsReward} points!",
            'achievement_redeemed'
        );

        return response()->json(['message' => 'Achievement redeemed.']);
    }
}
