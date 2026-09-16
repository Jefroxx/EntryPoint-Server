<?php

namespace App\Http\Controllers\Librarian;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AchievementController extends Controller
{
    public function index()
    {
        $achievements = Achievement::withCount([
            'students as unlockedCount',
            'students as redeemedCount' => fn ($q) => $q->wherePivotNotNull('redeemedAt'),
        ])->orderBy('name')->get();

        return response()->json(['achievements' => $achievements]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $achievement = DB::transaction(function () use ($validated) {
            $achievement = Achievement::create([
                'uuid'         => Str::uuid(),
                'name'         => $validated['name'],
                'criteriaJSON' => $validated['criteriaJSON'],
                'pointsReward' => $validated['pointsReward'],
            ]);

            $achievement->evaluateForAllStudents();

            return $achievement;
        });

        return response()->json([
            'message'     => 'Achievement created.',
            'achievement' => $achievement,
        ], 201);
    }

    public function update(Request $request, Achievement $achievement)
    {
        $validated = $this->validated($request, sometimes: true);

        DB::transaction(function () use ($validated, $achievement) {
            $achievement->update($validated);

            if (array_key_exists('criteriaJSON', $validated)) {
                $achievement->evaluateForAllStudents();
            }
        });

        return response()->json([
            'message'     => 'Achievement updated.',
            'achievement' => $achievement->fresh(),
        ]);
    }

    public function destroy(Achievement $achievement)
    {
        $achievement->delete();

        return response()->json(['message' => 'Achievement removed.']);
    }

    private function validated(Request $request, bool $sometimes = false): array
    {
        $rule = fn (string $default) => $sometimes ? 'sometimes' : $default;

        return $request->validate([
            'name'                     => [$rule('required'), 'string', 'max:150'],
            'criteriaJSON'             => [$rule('required'), 'array'],
            'criteriaJSON.metric'      => [$rule('required'), Rule::in(Achievement::CRITERIA_METRICS)],
            'criteriaJSON.threshold'   => [$rule('required'), 'integer', 'min:1'],
            'pointsReward'             => [$rule('required'), 'integer', 'min:0'],
        ]);
    }
}
