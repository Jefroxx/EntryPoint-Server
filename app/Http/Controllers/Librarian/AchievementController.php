<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAchievementRequest;
use App\Http\Requests\UpdateAchievementRequest;
use App\Models\Achievement;
use App\Services\AchievementService;

class AchievementController extends Controller
{
    public function __construct(private AchievementService $achievementService)
    {
    }

    public function index()
    {
        return response()->json([
            'achievements' => $this->achievementService->listWithCounts(),
            // What a new achievement can be about, for the "Unlocks when" choice.
            'metrics'      => $this->achievementService->metricCatalog(),
        ]);
    }

    public function store(StoreAchievementRequest $request)
    {
        $achievement = $this->achievementService->create($request->validated());

        return response()->json([
            'message'     => 'Achievement created.',
            'achievement' => $achievement,
        ], 201);
    }

    public function update(UpdateAchievementRequest $request, Achievement $achievement)
    {
        $achievement = $this->achievementService->update($achievement, $request->validated());

        return response()->json([
            'message'     => 'Achievement updated.',
            'achievement' => $achievement,
        ]);
    }

    public function destroy(Achievement $achievement)
    {
        $this->achievementService->delete($achievement);

        return response()->json(['message' => 'Achievement removed.']);
    }
}
