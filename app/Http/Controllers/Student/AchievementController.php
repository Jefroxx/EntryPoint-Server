<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Services\AchievementService;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function __construct(private AchievementService $achievementService)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'achievements' => $this->achievementService->indexForStudent($request->user()->student),
        ]);
    }

    public function redeem(Request $request, Achievement $achievement)
    {
        $this->achievementService->redeem($achievement, $request->user()->student);

        return response()->json(['message' => 'Achievement redeemed.']);
    }
}
